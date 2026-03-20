<?php

namespace bensomething\craftdub\migrations;

use craft\db\Migration;

class m260320_000000_add_site_id_to_dub_links extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%dub_links}}', 'siteId')) {
            $this->addColumn('{{%dub_links}}', 'siteId', $this->integer()->notNull()->defaultValue(1)->after('entryId'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%dub_links}}', 'siteId')) {
            $this->dropColumn('{{%dub_links}}', 'siteId');
        }

        return true;
    }
}
