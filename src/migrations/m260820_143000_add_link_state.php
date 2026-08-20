<?php

namespace bensomething\craftdub\migrations;

use craft\db\Migration;

/**
 * Adds the two columns prepareLink() needs to tell an unchanged save from a real one.
 *
 * Until now every save of a linked entry PATCHed Dub whether the destination had moved or
 * not, so one `resave/entries` was one HTTP round-trip per entry per site. Recording what
 * was last sent makes the no-op case free.
 *
 * `destinationUrl` is deliberately left null on existing rows: a null reads as "unknown",
 * which forces one PATCH per link on its next save and re-syncs the column from the response.
 */
class m260820_143000_add_link_state extends Migration
{
    private const TABLE = '{{%dub_links}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::TABLE, 'destinationUrl')) {
            $this->addColumn(self::TABLE, 'destinationUrl', $this->string(500));
        }

        if (!$this->db->columnExists(self::TABLE, 'archived')) {
            $this->addColumn(self::TABLE, 'archived', $this->boolean()->notNull()->defaultValue(false));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::TABLE, 'archived')) {
            $this->dropColumn(self::TABLE, 'archived');
        }

        if ($this->db->columnExists(self::TABLE, 'destinationUrl')) {
            $this->dropColumn(self::TABLE, 'destinationUrl');
        }

        return true;
    }
}
