<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Mongo;

use MongoDB\Client;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface;

/**
 * Read-only inspection of one or more MongoDB servers for the back-office "MongoDB Viewer" tool.
 *
 * To fit the OroCloud network scope two connections are supported, each with its own DSN:
 *  - "private" — the internal/private endpoint; falls back to the application's ORO_MONGODB_SERVER
 *    when its DSN is left empty, preserving the out-of-the-box behaviour.
 *  - "public"  — an optional externally-reachable endpoint; only used when its DSN is set.
 *
 * Each DSN is stored in clear (host/port/db, and optionally a username); its password is kept in a
 * separate encrypted config field (OroEncodedPlaceholderPasswordType) and supplied to the driver via
 * uriOptions when a client is built, so the DSN can be shown without leaking the secret. Only read
 * operations are issued.
 */
class MongoInspector
{
    /** Max documents returned per page and the hard cap on the requested limit. */
    public const int DEFAULT_LIMIT = 20;
    public const int MAX_LIMIT = 100;

    /** Connection keys in display order. */
    public const string CONNECTION_PRIVATE = 'private';
    public const string CONNECTION_PUBLIC = 'public';

    public function __construct(
        private readonly string $defaultDsn,
        private readonly ConfigManager $configManager,
        private readonly SymmetricCrypterInterface $crypter,
    ) {
    }

    /**
     * Overview of every configured connection, each with its server version and databases. A
     * connection that cannot be reached carries an "error" instead of failing the whole overview,
     * so one endpoint being down never hides the other.
     *
     * @return array{connections: array<int, array{
     *     connection: string,
     *     server: array<string, string>,
     *     databases: array<int, array{db: string, collections: int, sizeOnDisk: int, connection: string}>,
     *     error?: string
     * }>}
     */
    public function getOverview(): array
    {
        $connections = [];
        foreach ($this->availableConnections() as $key) {
            $entry = ['connection' => $key, 'server' => [], 'databases' => []];
            try {
                $client = $this->client($key);

                try {
                    $build = $client->getManager()->executeCommand(
                        'admin',
                        new \MongoDB\Driver\Command(['buildInfo' => 1])
                    )->toArray()[0] ?? null;
                    if ($build !== null) {
                        $entry['server']['Version'] = (string) ($build->version ?? '');
                    }
                } catch (\Throwable) {
                    // serverStatus/buildInfo may be restricted; keep the overview best-effort.
                }

                foreach ($client->listDatabases() as $database) {
                    $name = $database->getName();
                    $entry['databases'][] = [
                        'db' => $name,
                        'collections' => $this->countCollections($client, $name),
                        'sizeOnDisk' => (int) $database->getSizeOnDisk(),
                        'connection' => $key,
                    ];
                }
            } catch (\Throwable $e) {
                $entry['error'] = $this->redactDsnCredentials($e->getMessage());
            }
            $connections[] = $entry;
        }

        return ['connections' => $connections];
    }

    /**
     * @return array<int, array{name: string, count: int}>
     */
    public function listCollections(string $connection, string $db): array
    {
        $database = $this->client($connection)->selectDatabase($db);
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
    public function findDocuments(
        string $connection,
        string $db,
        string $collection,
        string $filterJson,
        int $skip,
        int $limit
    ): array {
        $coll = $this->client($connection)->selectDatabase($db)->selectCollection($collection);
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
     * Pings every configured connection and reports each one's status; succeeds only when all
     * reachable connections acknowledge the ping. Honors the values currently entered in the config
     * form (even before saving) via $overrides; passwords are never returned.
     *
     * @param array<string, string> $overrides values entered in the config form (unsaved)
     *
     * @return array{success: bool, message: string, details: array<string, string>}
     */
    public function testConnection(array $overrides = []): array
    {
        $connections = $this->availableConnections($overrides);
        if (!$connections) {
            return [
                'success' => false,
                'message' => 'No MongoDB DSN is configured (neither public nor private).',
                'details' => [],
            ];
        }

        $details = [];
        $failures = [];
        foreach ($connections as $key) {
            $label = ucfirst($key);
            $masked = $this->maskDsn($this->effectiveDsn($key, $overrides));
            try {
                $client = $this->client($key, $overrides);
                $result = $client->getManager()->executeCommand(
                    'admin',
                    new \MongoDB\Driver\Command(['ping' => 1])
                )->toArray();
                if (($result[0]->ok ?? 0) != 1) {
                    $failures[] = $label . ': MongoDB did not acknowledge the ping.';
                    $details[$label] = $masked . ' — not acknowledged';
                    continue;
                }
                $build = $client->getManager()->executeCommand(
                    'admin',
                    new \MongoDB\Driver\Command(['buildInfo' => 1])
                )->toArray()[0] ?? null;
                $version = $build !== null ? (string) ($build->version ?? '') : '';
                $details[$label] = $masked . ($version !== '' ? ' (v' . $version . ')' : '');
            } catch (\Throwable $e) {
                $failures[] = $label . ': ' . $this->redactDsnCredentials($e->getMessage());
                $details[$label] = $masked . ' — failed';
            }
        }

        if ($failures) {
            return ['success' => false, 'message' => 'MongoDB connection failed: ' . implode(' ', $failures), 'details' => $details];
        }

        return ['success' => true, 'message' => 'MongoDB connection succeeded.', 'details' => $details];
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

    /**
     * @param array<string, string> $overrides
     */
    private function client(string $connection, array $overrides = []): Client
    {
        $dsn = $this->effectiveDsn($connection, $overrides);
        if ($dsn === '') {
            throw new \InvalidArgumentException(sprintf('No MongoDB DSN is configured for the "%s" connection.', $connection));
        }

        // The password lives in its own field, not in the DSN; the driver merges it (and the
        // username carried in the DSN) without us having to splice credentials into the URI string.
        $uriOptions = [];
        $password = $this->effectivePassword($connection, $overrides);
        if ($password !== '') {
            $uriOptions['password'] = $password;
        }

        return new Client($dsn, $uriOptions);
    }

    /**
     * Connection keys (in display order) that have a usable DSN.
     *
     * @param array<string, string> $overrides
     *
     * @return array<int, string>
     */
    private function availableConnections(array $overrides = []): array
    {
        $available = [];
        foreach ([self::CONNECTION_PRIVATE, self::CONNECTION_PUBLIC] as $key) {
            if ($this->effectiveDsn($key, $overrides) !== '') {
                $available[] = $key;
            }
        }

        return $available;
    }

    /**
     * The resolved DSN for a connection: the value entered in the form (unsaved) when present, then
     * the configured value, with the private connection falling back to the application's
     * ORO_MONGODB_SERVER when left empty.
     *
     * @param array<string, string> $overrides
     */
    private function effectiveDsn(string $connection, array $overrides = []): string
    {
        $entered = $overrides[$connection . '_dsn'] ?? null;
        if ($entered !== null && trim((string) $entered) !== '') {
            return trim((string) $entered);
        }

        $configured = trim((string) $this->configManager->get('aaxis_devtools.mongodb_viewer_' . $connection . '_dsn'));
        if ($configured !== '') {
            return $configured;
        }

        return $connection === self::CONNECTION_PRIVATE ? trim($this->defaultDsn) : '';
    }

    /**
     * The password for a connection: the value typed into the form (test in edit mode) when present,
     * otherwise the saved encrypted value, decrypted. The masked placeholder rendered for a stored
     * secret (a run of "*") counts as "not entered".
     *
     * @param array<string, string> $overrides
     */
    private function effectivePassword(string $connection, array $overrides): string
    {
        $entered = isset($overrides[$connection . '_password']) ? trim((string) $overrides[$connection . '_password']) : '';
        if ($entered !== '' && trim($entered, '*') !== '') {
            return $entered;
        }

        return $this->decrypt((string) $this->configManager->get('aaxis_devtools.mongodb_viewer_' . $connection . '_password'));
    }

    /**
     * Decrypts a value stored by OroEncodedPlaceholderPasswordType; an unset or undecryptable value
     * yields an empty string.
     */
    private function decrypt(string $encrypted): string
    {
        $encrypted = trim($encrypted);
        if ($encrypted === '') {
            return '';
        }

        try {
            return trim((string) $this->crypter->decryptData($encrypted));
        } catch (\Throwable) {
            return '';
        }
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
