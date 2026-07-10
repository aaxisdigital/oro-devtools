<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Migrations\Schema\v1_1;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Adds the `tool` discriminator to the shared saved-query table so the Database Viewer and the
 * Elastic Viewer can keep separate favourites in the same table. Existing rows default to
 * 'database' (they were all Database Viewer queries before the Elastic Viewer gained saving).
 *
 * Fresh installs already get this column from {@see AaxisDevToolsBundleInstaller}; this migration
 * brings databases installed at v1_0 up to date. It is written to be idempotent so it is safe even
 * if the column was added manually beforehand.
 */
class AddSavedQueryToolColumn implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $table = $schema->getTable('aaxis_dbviewer_saved_query');

        if (!$table->hasColumn('tool')) {
            $table->addColumn('tool', 'string', ['length' => 16, 'default' => 'database', 'notnull' => true]);
        }

        // Re-scope the public listing index by tool to match the installer's definition.
        if ($table->hasIndex('aaxis_saved_query_public_idx')) {
            $table->dropIndex('aaxis_saved_query_public_idx');
        }
        $table->addIndex(['tool', 'is_public', 'created_at'], 'aaxis_saved_query_public_idx');
    }
}
