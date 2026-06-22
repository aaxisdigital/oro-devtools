<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Database;

use Doctrine\DBAL\Connection;

/**
 * Provides safe, read-only introspection and query execution against the application database.
 *
 * Safety guarantees for {@see self::executeReadOnlyQuery()}:
 *  - the statement runs inside a READ ONLY transaction (writes/DDL are rejected by the database);
 *  - a per-statement timeout is applied (default 60s);
 *  - at most {@see self::MAX_ROWS} rows are returned in the payload.
 */
class DatabaseInspector
{
    /** Maximum number of rows returned to the client. */
    public const int MAX_ROWS = 1000;

    /** Per-statement timeout in milliseconds (1 minute). */
    public const int STATEMENT_TIMEOUT_MS = 60000;

    public const string TYPE_TABLE = 'table';
    public const string TYPE_VIEW = 'view';
    public const string TYPE_FUNCTION = 'function';

    public const string COUNT_NONE = 'none';
    public const string COUNT_QUANTITY = 'quantity';
    public const string COUNT_BYTES = 'bytes';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Returns the sorted list of table names available in the current database.
     *
     * @return string[]
     */
    public function getTableNames(): array
    {
        $tables = $this->connection->createSchemaManager()->listTableNames();
        sort($tables);

        return $tables;
    }

    /**
     * Returns the sorted list of view names (system schemas excluded).
     *
     * @return string[]
     */
    public function getViewNames(): array
    {
        $sql = <<<'SQL'
            SELECT table_name
            FROM information_schema.views
            WHERE table_schema NOT IN ('pg_catalog', 'information_schema')
            ORDER BY table_name
            SQL;

        return array_map('strval', $this->connection->fetchFirstColumn($sql));
    }

    /**
     * Returns the sorted list of user-defined function names (system schemas excluded).
     *
     * @return string[]
     */
    public function getFunctionNames(): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT routine_name
            FROM information_schema.routines
            WHERE routine_type = 'FUNCTION'
              AND routine_schema NOT IN ('pg_catalog', 'information_schema')
            ORDER BY routine_name
            SQL;

        return array_map('strval', $this->connection->fetchFirstColumn($sql));
    }

    /**
     * Returns database objects (tables, then views, then functions), each sorted alphabetically.
     *
     * @param bool   $includeViews     whether to include views
     * @param bool   $includeFunctions whether to include functions
     * @param string $countMode        'none' | 'quantity' (estimated rows) | 'bytes' (total size)
     *
     * @return array<int, array{name: string, type: string, count?: string}>
     */
    public function getDatabaseObjects(
        bool $includeViews = true,
        bool $includeFunctions = true,
        string $countMode = 'none'
    ): array {
        $views = $this->getViewNames();
        $viewLookup = array_fill_keys($views, true);
        $counts = $countMode === self::COUNT_NONE ? [] : $this->getCountMap($countMode);

        $objects = [];
        foreach ($this->getTableNames() as $name) {
            // Some drivers report views among table names; keep them under the "view" type only.
            if (isset($viewLookup[$name])) {
                continue;
            }
            $objects[] = $this->buildObject($name, self::TYPE_TABLE, $counts);
        }
        if ($includeViews) {
            foreach ($views as $name) {
                $objects[] = $this->buildObject($name, self::TYPE_VIEW, $counts);
            }
        }
        if ($includeFunctions) {
            foreach ($this->getFunctionNames() as $name) {
                $objects[] = ['name' => $name, 'type' => self::TYPE_FUNCTION];
            }
        }

        return $objects;
    }

    /**
     * @param array<string, array{display: string, value: int}> $counts
     * @return array{name: string, type: string, count?: string, countValue?: int}
     */
    private function buildObject(string $name, string $type, array $counts): array
    {
        $object = ['name' => $name, 'type' => $type];
        if (isset($counts[$name])) {
            $object['count'] = $counts[$name]['display'];
            $object['countValue'] = $counts[$name]['value'];
        }

        return $object;
    }

    /**
     * Builds a map of object name => {display, value} for tables, where "display" is the
     * human-readable count/size and "value" is the raw number used for sorting.
     *
     * @return array<string, array{display: string, value: int}>
     */
    private function getCountMap(string $mode): array
    {
        if ($mode === self::COUNT_BYTES) {
            $sql = <<<'SQL'
                SELECT c.relname AS name,
                       pg_total_relation_size(c.oid) AS val,
                       pg_size_pretty(pg_total_relation_size(c.oid)) AS display
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
                  AND c.relkind IN ('r', 'p')
                SQL;
        } else {
            $sql = <<<'SQL'
                SELECT c.relname AS name, c.reltuples::bigint AS val
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
                  AND c.relkind IN ('r', 'p')
                SQL;
        }

        $map = [];
        $needExact = [];
        foreach ($this->connection->fetchAllAssociative($sql) as $row) {
            $name = (string) $row['name'];
            if ($mode === self::COUNT_BYTES) {
                $map[$name] = ['display' => (string) $row['display'], 'value' => (int) $row['val']];
                continue;
            }
            // reltuples is an estimate (-1 when never analyzed, and can lag after bulk inserts).
            // Trust it only when positive; otherwise fall back to an exact count below.
            $value = (int) $row['val'];
            if ($value > 0) {
                $map[$name] = ['display' => (string) $value, 'value' => $value];
            } else {
                $needExact[] = $name;
            }
        }

        // Exact COUNT(*) for tables the estimate reports as empty/unanalyzed, so small or
        // freshly-populated tables show their real row count instead of 0.
        foreach ($needExact as $name) {
            try {
                $exact = (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM ' . $this->connection->quoteIdentifier($name)
                );
            } catch (\Throwable) {
                $exact = 0;
            }
            $map[$name] = ['display' => (string) $exact, 'value' => $exact];
        }

        return $map;
    }

    /**
     * Returns the columns of a table or view, each with a Postgres-style formatted type
     * (e.g. "int4(32,0)", "varchar(255)", "jsonb", "timestamp(0)").
     *
     * @return array<int, array{name: string, type: string, nullable: bool}>
     */
    public function getTableColumns(string $name): array
    {
        $sql = <<<'SQL'
            SELECT column_name,
                   udt_name,
                   is_nullable,
                   character_maximum_length AS char_len,
                   numeric_precision,
                   numeric_scale,
                   datetime_precision
            FROM information_schema.columns
            WHERE table_schema NOT IN ('pg_catalog', 'information_schema')
              AND table_name = :name
            ORDER BY ordinal_position
            SQL;

        $columns = [];
        foreach ($this->connection->fetchAllAssociative($sql, ['name' => $name]) as $row) {
            $columns[] = [
                'name' => (string) $row['column_name'],
                'type' => $this->formatColumnType($row),
                'nullable' => ($row['is_nullable'] ?? 'YES') === 'YES',
            ];
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function formatColumnType(array $row): string
    {
        $udt = (string) ($row['udt_name'] ?? '');
        $udt = ltrim($udt, '_'); // array types are reported as e.g. "_int4"

        if ($row['char_len'] !== null) {
            return sprintf('%s(%d)', $udt, (int) $row['char_len']);
        }
        if ($row['numeric_precision'] !== null) {
            return sprintf('%s(%d,%d)', $udt, (int) $row['numeric_precision'], (int) ($row['numeric_scale'] ?? 0));
        }
        if ($row['datetime_precision'] !== null && \in_array($udt, ['timestamp', 'timestamptz', 'time', 'timetz', 'interval'], true)) {
            return sprintf('%s(%d)', $udt, (int) $row['datetime_precision']);
        }

        return $udt;
    }

    /**
     * Executes a read-only SQL statement and returns a bounded result set.
     *
     * @return array{
     *     columns: string[],
     *     rows: array<int, array<string, mixed>>,
     *     rowCount: int,
     *     truncated: bool,
     *     durationMs: int
     * }
     *
     * @throws \InvalidArgumentException when the query is empty
     * @throws \Doctrine\DBAL\Exception when the statement fails (e.g. a write in a read-only transaction)
     */
    public function executeReadOnlyQuery(string $sql): array
    {
        $sql = trim($sql);
        if ($sql === '') {
            throw new \InvalidArgumentException('The query must not be empty.');
        }

        $startedAt = microtime(true);

        $this->connection->beginTransaction();
        try {
            // Enforce the safety constraints at the database level (PostgreSQL).
            $this->connection->executeStatement('SET TRANSACTION READ ONLY');
            $this->connection->executeStatement(
                sprintf('SET LOCAL statement_timeout = %d', self::STATEMENT_TIMEOUT_MS)
            );

            $result = $this->connection->executeQuery($sql);

            $columns = [];
            $rows = [];
            $truncated = false;
            foreach ($result->iterateAssociative() as $row) {
                if (\count($rows) >= self::MAX_ROWS) {
                    $truncated = true;
                    break;
                }
                if ($columns === []) {
                    $columns = array_keys($row);
                }
                $rows[] = $row;
            }

            // The transaction is read-only; roll back to release it cleanly.
            $this->connection->rollBack();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $e;
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'rowCount' => \count($rows),
            'truncated' => $truncated,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }
}
