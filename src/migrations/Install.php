<?php

namespace bensomething\craftdub\migrations;

use craft\db\Migration;

class Install extends Migration
{
    private const TABLE = '{{%dub_links}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            // A table left behind by an older uninstall. Craft stamps the current
            // schemaVersion at install time and clears the plugin's rows from the migrations
            // table, so nothing else will ever run against it — returning early here would
            // leave it short of whatever columns have been added since, permanently, while
            // Craft reported the schema as up to date. Reconcile it instead.
            $this->addMissingColumns();

            return true;
        }

        $this->createTable(self::TABLE, [
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

        $this->addForeignKey(null, self::TABLE, 'entryId', '{{%elements}}', 'id', 'CASCADE');

        return true;
    }

    /**
     * Brings an existing table up to the current shape.
     *
     * Keyed by column name rather than compared against the create above, so a column added in
     * a later release only has to be listed once — here and in safeUp() — and this stays the
     * single place that repairs an old table.
     */
    private function addMissingColumns(): void
    {
        $columns = [
            'destinationUrl' => $this->string(500),
            'archived' => $this->boolean()->notNull()->defaultValue(false),
        ];

        foreach ($columns as $name => $definition) {
            if (!$this->db->columnExists(self::TABLE, $name)) {
                $this->addColumn(self::TABLE, $name, $definition);
            }
        }
    }

    public function safeDown(): bool
    {
        // Craft stamps the current schemaVersion at install time and clears the plugin's rows
        // from the migrations table, so a reinstall runs safeUp() and nothing else. Dropping
        // here is half of keeping that honest; safeUp() reconciling an existing table is the
        // other half, for tables left behind by a version that didn't drop.
        //
        // Losing the table costs the local mapping, not the links: Dub keeps them, externalId
        // and all, so `php craft dub/adopt` rebuilds it.
        $this->dropTableIfExists(self::TABLE);

        return true;
    }
}
