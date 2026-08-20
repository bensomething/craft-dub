<?php

namespace bensomething\craftdub\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%dub_links}}')) {
            $this->createTable('{{%dub_links}}', [
                'id' => $this->primaryKey(),
                'entryId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'dubLinkId' => $this->string(),
                'shortLink' => $this->string(500),
                // What was last sent to Dub, so an unchanged save can skip the API call.
                'destinationUrl' => $this->string(500),
                'archived' => $this->boolean()->notNull()->defaultValue(false),
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
        // Craft stamps the current schemaVersion at install time and clears the plugin's rows
        // from the migrations table, so a reinstall runs safeUp() and nothing else. Leaving
        // the table behind meant safeUp()'s tableExists guard adopted whatever shape the old
        // one had, with no migration left that could bring it up to date.
        //
        // Losing the table costs the local mapping, not the links: Dub keeps them, externalId
        // and all, so `php craft dub/adopt` rebuilds it.
        $this->dropTableIfExists('{{%dub_links}}');

        return true;
    }
}
