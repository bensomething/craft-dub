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
    public string $domain = '';

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
    public function validateDomain(string $attribute): void
    {
        $value = $this->$attribute;

        if (empty($value) || str_starts_with($value, '$')) {
            return;
        }

        $domains = Plugin::getInstance()->dub->getDomains();
        if (empty($domains)) {
            return;
        }

        if (!in_array($value, array_column($domains, 'slug'), true)) {
            $this->addError($attribute, Craft::t('dub', 'That domain isn’t available in your Dub workspace.'));
        }
    }
}
