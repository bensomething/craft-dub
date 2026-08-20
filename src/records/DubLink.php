<?php

namespace bensomething\craftdub\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $entryId
 * @property int $siteId
 * @property string|null $dubLinkId
 * @property string|null $shortLink
 * @property string|null $destinationUrl
 * @property bool $archived
 */
class DubLink extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%dub_links}}';
    }
}
