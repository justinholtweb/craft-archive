<?php

namespace justinholtweb\archive\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Installs Archive's bundle ledger.
 */
class Install extends Migration
{
    public const TABLE_BUNDLES = '{{%archive_bundles}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE_BUNDLES)) {
            return true;
        }

        $this->createTable(self::TABLE_BUNDLES, [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'filename' => $this->string(255),
            'format' => $this->string(32)->notNull()->defaultValue('json'),
            'status' => $this->string(32)->notNull()->defaultValue('pending'),
            'size' => $this->bigInteger()->unsigned(),
            'counts' => $this->text(),
            'config' => $this->text(),
            'warnings' => $this->text(),
            'error' => $this->text(),
            'creatorId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TABLE_BUNDLES, ['status']);
        $this->createIndex(null, self::TABLE_BUNDLES, ['dateCreated']);

        $this->addForeignKey(
            null,
            self::TABLE_BUNDLES,
            ['creatorId'],
            Table::USERS,
            ['id'],
            'SET NULL',
            null
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE_BUNDLES);
        return true;
    }
}
