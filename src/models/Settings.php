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
    public string $apiKey = '';
    public string $domain = '';
    public array|string $sections = ['*'];

    public function rules(): array
    {
        return [
            [['apiKey', 'domain'], 'string'],
            [['domain'], 'validateDomain'],
            [['sections'], 'filter', 'filter' => function($value) {
                if (!is_array($value)) {
                    return ['*'];
                }
                return empty($value) ? ['*'] : $value;
            }],
        ];
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
