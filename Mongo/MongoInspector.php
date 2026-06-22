<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Mongo;

use MongoDB\Client;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;

/**
 * Read-only inspection of a MongoDB server for the back-office "MongoDB Viewer" tool.
 *
 * Uses the mongodb/mongodb library (phpredis-like). The connection DSN comes from configuration,
 * falling back to the application's ORO_MONGODB_SERVER. Only read operations are issued.
 */
class MongoInspector
{
    /** Max documents returned per page and the hard cap on the requested limit. */
    public const int DEFAULT_LIMIT = 20;
    public const int MAX_LIMIT = 100;

    public function __construct(
        private readonly string $defaultDsn,
        private readonly ConfigManager $configManager,
    ) {
    }

    /**
     * @return array{
     *     server: array<string, string>,
     *     databases: array<int, array{db: string, collections: int, sizeOnDisk: int}>
     * }
     */
    public function getOverview(): array
    {
        $client = $this->client();

        $server = [];
        try {
            $build = $client->getManager()->executeCommand(
                'admin',
                new \MongoDB\Driver\Command(['buildInfo' => 1])
            )->toArray()[0] ?? null;
            if ($build !== null) {
                $server['Version'] = (string) ($build->version ?? '');
            }
        } catch (\Throwable) {
            // serverStatus/buildInfo may be restricted; keep the overview best-effort.
        }

        $databases = [];
        foreach ($client->listDatabases() as $database) {
            $name = $database->getName();
            $databases[] = [
                'db' => $name,
                'collections' => $this->countCollections($client, $name),
                'sizeOnDisk' => (int) $database->getSizeOnDisk(),
            ];
        }

        return ['server' => $server, 'databases' => $databases];
    }

    /**
     * @return array<int, array{name: string, count: int}>
     */
    public function listCollections(string $db): array
    {
        $database = $this->client()->selectDatabase($db);
        $collections = [];
        foreach ($database->listCollections() as $collection) {
            $name = $collection->getName();
            $count = 0;
            try {
                $count = $database->selectCollection($name)->estimatedDocumentCount();
            } catch (\Throwable) {
                // views and capped collections may not support estimated counts
            }
            $collections[] = ['name' => $name, 'count' => $count];
        }
        usort($collections, static fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $collections;
    }

    /**
     * Finds a page of documents in a collection.
     *
     * @return array{total: int, documents: array<int, array<string, mixed>>, truncated: bool}
     */
    public function findDocuments(string $db, string $collection, string $filterJson, int $skip, int $limit): array
    {
        $coll = $this->client()->selectDatabase($db)->selectCollection($collection);
        $filter = $this->parseFilter($filterJson);
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $skip = max(0, $skip);

        $total = $coll->countDocuments($filter);

        $documents = [];
        $cursor = $coll->find($filter, ['skip' => $skip, 'limit' => $limit]);
        foreach ($cursor as $document) {
            // BSONDocument is JsonSerializable (Extended JSON); decode to a plain array for the UI.
            $documents[] = json_decode(json_encode($document), true);
        }

        return ['total' => $total, 'documents' => $documents, 'truncated' => $total > $skip + \count($documents)];
    }

    /**
     * @return array{success: bool, message: string, details: array<string, string>}
     */
    public function testConnection(): array
    {
        $dsn = $this->effectiveDsn();
        $details = ['Server' => $this->maskDsn($dsn)];

        try {
            $client = $this->client();
            $result = $client->getManager()->executeCommand(
                'admin',
                new \MongoDB\Driver\Command(['ping' => 1])
            )->toArray();
            if (($result[0]->ok ?? 0) != 1) {
                return ['success' => false, 'message' => 'MongoDB did not acknowledge the ping.', 'details' => $details];
            }
            $build = $client->getManager()->executeCommand(
                'admin',
                new \MongoDB\Driver\Command(['buildInfo' => 1])
            )->toArray()[0] ?? null;
            if ($build !== null) {
                $details['Version'] = (string) ($build->version ?? '');
            }

            return ['success' => true, 'message' => 'MongoDB connection succeeded.', 'details' => $details];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'MongoDB connection failed: ' . $e->getMessage(), 'details' => $details];
        }
    }

    private function countCollections(Client $client, string $db): int
    {
        try {
            return iterator_count($client->selectDatabase($db)->listCollections());
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFilter(string $filterJson): array
    {
        $filterJson = trim($filterJson);
        if ($filterJson === '' || $filterJson === '{}') {
            return [];
        }
        $decoded = json_decode($filterJson, true);
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('The filter must be a valid JSON object.');
        }

        return $decoded;
    }

    private function client(): Client
    {
        return new Client($this->effectiveDsn());
    }

    private function effectiveDsn(): string
    {
        $configured = trim((string) $this->configManager->get('aaxis_devtools.mongodb_viewer_dsn'));

        return $configured !== '' ? $configured : $this->defaultDsn;
    }

    private function maskDsn(string $dsn): string
    {
        // Hide any credentials embedded in the DSN (mongodb://user:pass@host).
        return $this->redactDsnCredentials($dsn);
    }

    /**
     * Strips credentials from any DSN/URI embedded in a string (e.g. a driver exception message),
     * so a connection error can be surfaced to the client without leaking mongodb://user:pass@host.
     */
    public function redactDsnCredentials(string $text): string
    {
        return (string) preg_replace('#://[^@/\s]+@#', '://***@', $text);
    }
}
