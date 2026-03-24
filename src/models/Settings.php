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
    public array $sections = [];

    public function rules(): array
    {
        return [
            [['apiKey', 'domain'], 'string'],
            [['sections'], 'each', 'rule' => ['integer']],
        ];
    }
}
