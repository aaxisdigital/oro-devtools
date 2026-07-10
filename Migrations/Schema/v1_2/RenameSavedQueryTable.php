<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Migrations\Schema\v1_2;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Renames the saved-query table from the Database-Viewer-specific `aaxis_dbviewer_saved_query` to
 * the neutral, shared `aaxis_saved_query` now that both the Database Viewer and the Elastic Viewer
 * store their favourites in it (scoped by the `tool` column).
 *
 * Fresh installs already create the table under the new name; this brings databases at v1_1 up to
 * date. Guarded so it is a no-op if the rename has already happened. `RENAME TO` works on both
 * PostgreSQL and MySQL and carries the columns, indexes and foreign key across.
 */
class RenameSavedQueryTable implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        if ($schema->hasTable('aaxis_dbviewer_saved_query') && !$schema->hasTable('aaxis_saved_query')) {
            $queries->addQuery('ALTER TABLE aaxis_dbviewer_saved_query RENAME TO aaxis_saved_query');
        }
    }
}
