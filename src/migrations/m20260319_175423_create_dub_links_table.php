<?php

namespace bensomething\craftdub\migrations;

use craft\db\Migration;

class m20260319_175423_create_dub_links_table extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%dub_links}}')) {
            $this->createTable('{{%dub_links}}', [
                'id' => $this->primaryKey(),
                'entryId' => $this->integer()->notNull(),
                'dubLinkId' => $this->string(),
                'shortLink' => $this->string(500),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey(null, '{{%dub_links}}', 'entryId', '{{%elements}}', 'id', 'CASCADE');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%dub_links}}');
        return true;
    }
}
