<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Migrations\Schema;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Installation;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Creates the AaxisDevToolsBundle database tables: the Database Viewer query history and saved
 * queries, and the Network Tools test history. Single, consolidated install reflecting the current
 * state of the schema.
 */
class AaxisDevToolsBundleInstaller implements Installation
{
    private const string JSONB_NULL = 'JSONB DEFAULT NULL';
    private const string FK_SET_NULL = 'SET NULL';

    #[\Override]
    public function getMigrationVersion(): string
    {
        return 'v1_0';
    }

    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $this->createQueryHistoryTable($schema);
        $this->createSavedQueryTable($schema);
        $this->createNetworkTestHistoryTable($schema);

        $this->addForeignKeys($schema);
    }

    private function createQueryHistoryTable(Schema $schema): void
    {
        $table = $schema->createTable('aaxis_dbviewer_query_history');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('user_id', 'integer', ['notnull' => false]);
        $table->addColumn('query', 'text', []);
        $table->addColumn('run_at', 'datetime', []);
        $table->addColumn('result', 'string', ['length' => 16, 'notnull' => false]);
        $table->addColumn('time_ms', 'integer', ['notnull' => false]);
        $table->addColumn('record_count', 'integer', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id'], 'aaxis_dbviewer_history_user_idx');
        $table->addIndex(['run_at'], 'aaxis_dbviewer_history_run_at_idx');
    }

    private function createSavedQueryTable(Schema $schema): void
    {
        $table = $schema->createTable('aaxis_dbviewer_saved_query');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('user_id', 'integer', ['notnull' => false]);
        $table->addColumn('is_public', 'boolean', ['default' => false]);
        $table->addColumn('query_name', 'string', ['length' => 40]);
        $table->addColumn('query_text', 'text', []);
        $table->addColumn('created_at', 'datetime', []);
        $table->addColumn('updated_at', 'datetime', []);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id'], 'aaxis_saved_query_user_idx');
        $table->addIndex(['is_public', 'created_at'], 'aaxis_saved_query_public_idx');
    }

    private function createNetworkTestHistoryTable(Schema $schema): void
    {
        $table = $schema->createTable('aaxis_network_test_history');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('user_id', 'integer', ['notnull' => false]);
        $table->addColumn('test', 'string', ['length' => 64]);
        $table->addColumn('payload', 'json', ['notnull' => false, 'columnDefinition' => self::JSONB_NULL]);
        $table->addColumn('response', 'text', ['notnull' => false]);
        $table->addColumn('run_at', 'datetime', []);
        $table->addColumn('result', 'string', ['length' => 16, 'notnull' => false]);
        $table->addColumn('time_ms', 'integer', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id'], 'aaxis_net_history_user_idx');
        $table->addIndex(['run_at'], 'aaxis_net_history_run_at_idx');
    }

    private function addForeignKeys(Schema $schema): void
    {
        $schema->getTable('aaxis_dbviewer_query_history')->addForeignKeyConstraint(
            $schema->getTable('oro_user'),
            ['user_id'],
            ['id'],
            ['onDelete' => self::FK_SET_NULL, 'onUpdate' => null]
        );

        $schema->getTable('aaxis_dbviewer_saved_query')->addForeignKeyConstraint(
            $schema->getTable('oro_user'),
            ['user_id'],
            ['id'],
            ['onDelete' => self::FK_SET_NULL, 'onUpdate' => null]
        );

        $schema->getTable('aaxis_network_test_history')->addForeignKeyConstraint(
            $schema->getTable('oro_user'),
            ['user_id'],
            ['id'],
            ['onDelete' => self::FK_SET_NULL, 'onUpdate' => null]
        );
    }
}
