<?php

namespace bensomething\craftdub\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $entryId
 * @property int $siteId
 * @property string|null $dubLinkId
 * @property string|null $shortLink
 */
class DubLink extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%dub_links}}';
    }
}
