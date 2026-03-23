<?php

namespace bensomething\craftdub\migrations;

use craft\db\Migration;

class m260323_000000_add_workspace_id_to_dub_links extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%dub_links}}', 'workspaceId')) {
            $this->addColumn('{{%dub_links}}', 'workspaceId', $this->string()->after('dubLinkId'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%dub_links}}', 'workspaceId')) {
            $this->dropColumn('{{%dub_links}}', 'workspaceId');
        }

        return true;
    }
}
