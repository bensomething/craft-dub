<?php

namespace bensomething\craftdub\models;

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

        return $this->resolveDomain($override) ?? $this->resolveDomain($this->domain) ?? '';
    }

    /**
     * A domain setting resolved to something usable, or null.
     *
     * Null covers a blank value and an environment variable that is not set here, which are the
     * same thing as far as this is concerned: nothing to use. Treating them alike is what lets
     * an override fall back to the default rather than leaving the site with no domain, which
     * would go unnoticed, since prepareLink() simply omits an empty domain and Dub then creates
     * the link on whatever its workspace default happens to be.
     */
    private function resolveDomain(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $resolved = Craft::parseEnv($value);

        return is_string($resolved) && $resolved !== '' ? $resolved : null;
    }

    /**
     * Whether this site's own domain is what gets used, rather than the default.
     *
     * An override pointing at an environment variable that is not set here does not count: the
     * site falls back to the default, so saying otherwise would be wrong exactly when it
     * matters.
     */
    public function hasResolvedOverride(int $siteId): bool
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);

        if ($site === null) {
            return false;
        }

        return $this->resolveDomain($this->getSiteDomains()[$site->uid] ?? '') !== null;
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

        return self::distinctDomains($domains);
    }

    /**
     * Drops blanks and duplicates from a list of resolved domains.
     *
     * Both matter to adoption, which pages the whole workspace once per domain. A duplicate
     * costs an entire redundant scan, and a blank costs an unfiltered pass that considers every
     * link in the workspace. Neither surfaces as an error, only as a slower run.
     *
     * @param array<mixed> $values
     * @return list<string>
     */
    private static function distinctDomains(array $values): array
    {
        $values = array_filter($values, static fn($value): bool => is_string($value) && $value !== '');

        return array_values(array_unique($values));
    }

    /**
     * Why a domain will not work, or null if it is fine.
     *
     * A warning rather than a validation error, for two reasons. The list of available domains
     * comes from the Dub API, so an outage or a rate limit means it comes back empty and the
     * check silently passes anyway: something that cannot be relied on to block should not be
     * blocking. And an environment variable resolves differently per environment, so a value
     * that is wrong on a laptop may be exactly right in production.
     *
     * Environment variables are resolved first and the result is what gets checked, so one
     * pointing somewhere that is not a Dub domain is caught rather than waved through for
     * beginning with a `$`. One that resolves to nothing is left alone, since it is presumably
     * set somewhere this is not.
     *
     * @param list<string> $available Domain slugs in the workspace. Empty means unknown, not none.
     */
    public function domainWarning(string $value, array $available): ?string
    {
        if ($value === '') {
            return null;
        }

        $resolved = $this->resolveDomain($value);

        // Checked before the workspace, because it needs no domain list and is true regardless
        // of whether one could be fetched. Not an error: a site falls back to the default and a
        // link still gets made. But silence would leave no way to tell that the override is
        // doing nothing here, which is the whole reason it looks like it is set.
        if ($resolved === null) {
            return Craft::t('dub', '{value} isn’t set in this environment.', ['value' => $value]);
        }

        // Empty means the workspace could not be read, not that it holds no domains.
        if ($available === [] || in_array($resolved, $available, true)) {
            return null;
        }

        if ($resolved !== $value) {
            return Craft::t('dub', '{value} resolves to {domain}, which isn’t in your Dub workspace.', [
                'value' => $value,
                'domain' => $resolved,
            ]);
        }

        return Craft::t('dub', '{domain} isn’t in your Dub workspace.', ['domain' => $resolved]);
    }
}
