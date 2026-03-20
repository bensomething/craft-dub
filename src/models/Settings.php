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

    public function rules(): array
    {
        return [
            [['apiKey', 'domain'], 'string'],
        ];
    }
}
