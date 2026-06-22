<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Elastic;

use Oro\Bundle\ElasticSearchBundle\Engine\IndexAgent;

/**
 * Provides read-only introspection and ES|QL execution against the application's Elasticsearch.
 *
 * ES|QL is a read-only query language, so no write protection is required; the result set is
 * nonetheless capped at {@see self::MAX_ROWS} rows for safety.
 */
class ElasticInspector
{
    /** Maximum number of rows returned to the client. */
    public const int MAX_ROWS = 1000;

    public function __construct(private readonly IndexAgent $indexAgent)
    {
    }

    /**
     * Returns the list of indices (system/hidden indices starting with "." are excluded),
     * sorted alphabetically, each with its document count and health.
     *
     * @return array<int, array{name: string, docsCount: int, size: string, health: string}>
     */
    public function getIndices(): array
    {
        $rows = $this->indexAgent->getClient()->cat()->indices([
            'format' => 'json',
            'h' => 'index,docs.count,store.size,health',
        ])->asArray();

        $indices = [];
        foreach ($rows as $row) {
            $name = (string) ($row['index'] ?? '');
            if ($name === '' || str_starts_with($name, '.')) {
                continue;
            }
            $indices[] = [
                'name' => $name,
                'docsCount' => (int) ($row['docs.count'] ?? 0),
                'size' => (string) ($row['store.size'] ?? ''),
                'health' => (string) ($row['health'] ?? ''),
            ];
        }

        usort($indices, static fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $indices;
    }

    /**
     * Executes an ES|QL query and returns a bounded, grid-friendly result set.
     *
     * @return array{
     *     columns: string[],
     *     columnTypes: array<string, string>,
     *     rows: array<int, array<string, mixed>>,
     *     rowCount: int,
     *     truncated: bool,
     *     durationMs: int
     * }
     *
     * @throws \InvalidArgumentException when the query is empty
     */
    public function runEsql(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new \InvalidArgumentException('The query must not be empty.');
        }

        $startedAt = microtime(true);

        $response = $this->indexAgent->getClient()->esql()->query([
            'body' => ['query' => $query],
        ])->asArray();

        $columnsMeta = $response['columns'] ?? [];
        $values = $response['values'] ?? [];

        $columns = [];
        $columnTypes = [];
        foreach ($columnsMeta as $meta) {
            $name = (string) ($meta['name'] ?? '');
            $columns[] = $name;
            $columnTypes[$name] = (string) ($meta['type'] ?? '');
        }

        $rows = [];
        $truncated = false;
        foreach ($values as $valueRow) {
            if (\count($rows) >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }
            $assoc = [];
            foreach ($columns as $index => $name) {
                $assoc[$name] = $valueRow[$index] ?? null;
            }
            $rows[] = $assoc;
        }

        return [
            'columns' => $columns,
            'columnTypes' => $columnTypes,
            'rows' => $rows,
            'rowCount' => \count($rows),
            'truncated' => $truncated,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }
}
