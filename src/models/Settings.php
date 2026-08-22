<?php

namespace bensomething\craftdub\models;

use bensomething\craftdub\Plugin;
use Craft;
use craft\base\Model;

/**
 * Dub settings
 */
class Settings extends Model
{
    public const QR_VIEW_MODES = ['none', 'icon', 'full'];
    public const QR_SIZE_MIN = 600;
    public const QR_MARGIN_MAX = 20;

    public string $apiKey = '';

    /**
     * The short link domain used by any site without an override.
     */
    public string $domain = '';

    /**
     * Per-site domain overrides, keyed by site UID.
     *
     * Keyed by UID rather than site ID so the setting survives project config moving between
     * environments, where the IDs differ.
     *
     * Typed loosely because this is raw CP input: the editable table posts
     * `[uid => ['domain' => '…']]`, but a posted value can be any shape. Read it normalised
     * via {@see getSiteDomains()} rather than directly.
     *
     * @var array<mixed>|string
     */
    public array|string $siteDomains = [];

    /**
     * Enabled section handles or UIDs, or `['*']` for all of them.
     *
     * @var list<string>|string
     */
    public array|string $sections = ['*'];

    public string $qrViewMode = 'none';
    public bool $showClicks = false;

    /**
     * QR code style, stored as a single editable-table row so it can be edited as one row of
     * columns in the CP. Read it normalised via {@see getQrStyle()} rather than directly.
     *
     * Typed loosely because this is raw CP input: the row is nominally
     * `{size, margin, foreground, background}` with string-or-int cells, but a posted value
     * can be any shape, which is why both the filter rule and {@see getQrStyle()} re-check it.
     *
     * @var list<mixed>|string
     */
    public array|string $qrStyle = [
        ['size' => 1200, 'margin' => 2, 'foreground' => '#000000', 'background' => '#ffffff'],
    ];

    public function rules(): array
    {
        return [
            [['apiKey', 'domain'], 'string'],
            [['domain'], 'validateDomain'],
            [['siteDomains'], 'validateSiteDomains'],
            [['qrViewMode'], 'in', 'range' => self::QR_VIEW_MODES],
            [['showClicks'], 'boolean'],
            [['sections'], 'filter', 'filter' => function($value) {
                if (!is_array($value)) {
                    return ['*'];
                }
                return empty($value) ? ['*'] : $value;
            }],
            [['qrStyle'], 'filter', 'filter' => function($value) {
                if (!is_array($value)) {
                    return [];
                }
                $row = reset($value);
                $row = is_array($row) ? $row : [];
                // Clamp only cells that have a value; leave blanks blank so they fall back
                // to Dub's own defaults (see getQrStyle()).
                if (trim((string)($row['size'] ?? '')) !== '') {
                    $row['size'] = max(self::QR_SIZE_MIN, (int)$row['size']);
                }
                if (trim((string)($row['margin'] ?? '')) !== '') {
                    $row['margin'] = min(self::QR_MARGIN_MAX, max(0, (int)$row['margin']));
                }
                return [$row];
            }],
        ];
    }

    /**
     * Returns the QR style as Dub `/qr` query parameters, omitting any the user left blank so
     * Dub applies its own defaults for those. Regardless of how the editable-table value is
     * shaped when posted.
     *
     * @return array<string, int|string>
     */
    public function getQrStyle(): array
    {
        $rows = is_array($this->qrStyle) ? $this->qrStyle : [];
        $row = $rows ? reset($rows) : [];
        $row = is_array($row) ? $row : [];

        $style = [];

        if (($size = trim((string)($row['size'] ?? ''))) !== '') {
            $style['size'] = max(self::QR_SIZE_MIN, (int)$size);
        }
        if (($margin = trim((string)($row['margin'] ?? ''))) !== '') {
            $style['margin'] = min(self::QR_MARGIN_MAX, max(0, (int)$margin));
        }
        if (($fg = trim((string)($row['foreground'] ?? ''))) !== '') {
            $style['fgColor'] = '#' . ltrim($fg, '#');
        }
        if (($bg = trim((string)($row['background'] ?? ''))) !== '') {
            $style['bgColor'] = '#' . ltrim($bg, '#');
        }

        return $style;
    }

    /**
     * Ensures a literal domain matches one tied to the Dub account. Skips environment
     * references (e.g. $DUB_DOMAIN) and degrades gracefully if the domains can't be fetched
     * (no API key yet, or the API is unreachable), so setup isn't blocked.
     */
    /**
     * The overrides as a plain uid => domain map, with blanks and junk dropped.
     *
     * @return array<string, string>
     */
    public function getSiteDomains(): array
    {
        if (!is_array($this->siteDomains)) {
            return [];
        }

        $domains = [];

        foreach ($this->siteDomains as $uid => $row) {
            // The table posts a row of cells; a value set in code may be the domain itself.
            $domain = is_array($row) ? ($row['domain'] ?? '') : $row;

            if (is_string($uid) && is_string($domain) && trim($domain) !== '') {
                $domains[$uid] = trim($domain);
            }
        }

        return $domains;
    }

    /**
     * The short link domain a given site's links belong on, with environment variables resolved.
     *
     * Falls back to the default domain, so an install that wants one domain everywhere carries
     * no overrides at all and behaves exactly as it did before per-site domains existed.
     */
    public function domainForSite(int $siteId): string
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $override = $site !== null ? ($this->getSiteDomains()[$site->uid] ?? '') : '';

        $value = Craft::parseEnv($override !== '' ? $override : $this->domain);

        return is_string($value) ? $value : '';
    }

    /**
     * Every distinct domain this install writes links to, resolved and deduplicated.
     *
     * Adoption pages the Dub workspace once per domain rather than scanning it unfiltered, so
     * it never takes over links on a domain nothing here manages.
     *
     * @return list<string>
     */
    public function configuredDomains(): array
    {
        $domains = [Craft::parseEnv($this->domain)];

        foreach ($this->getSiteDomains() as $override) {
            $domains[] = Craft::parseEnv($override);
        }

        $domains = array_filter($domains, static fn($domain): bool => is_string($domain) && $domain !== '');

        return array_values(array_unique($domains));
    }

    public function validateDomain(string $attribute): void
    {
        $value = $this->$attribute;

        if (empty($value) || str_starts_with($value, '$')) {
            return;
        }

        if (!$this->isAvailableDomain($value)) {
            $this->addError($attribute, Craft::t('dub', 'That domain isn’t available in your Dub workspace.'));
        }
    }

    /**
     * Holds the overrides to the same standard as the default domain, and names the site in the
     * error, since the table gives one row per site and an unattributed error would not say
     * which.
     */
    public function validateSiteDomains(string $attribute): void
    {
        foreach ($this->getSiteDomains() as $uid => $domain) {
            if (str_starts_with($domain, '$') || $this->isAvailableDomain($domain)) {
                continue;
            }

            $site = Craft::$app->getSites()->getSiteByUid($uid);

            $this->addError($attribute, Craft::t('dub', '{domain} isn’t available in your Dub workspace ({site}).', [
                'domain' => $domain,
                'site' => $site->name ?? $uid,
            ]));
        }
    }

    /**
     * Whether Dub has this domain. Answers true when the workspace can't be read at all, so a
     * missing or unreachable API key blocks nothing: the save is not the place to find out.
     */
    private function isAvailableDomain(string $domain): bool
    {
        $domains = Plugin::getInstance()->dub->getDomains();

        return empty($domains) || in_array($domain, array_column($domains, 'slug'), true);
    }
}
