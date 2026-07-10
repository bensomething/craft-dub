<?php

namespace bensomething\craftdub\models;

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
            [['sections'], 'filter', 'filter' => function($value) {
                if (!is_array($value)) {
                    return ['*'];
                }
                return empty($value) ? ['*'] : $value;
            }],
        ];
    }
}
