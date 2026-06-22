<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Redis;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;

/**
 * Read-only inspection of a Redis server for the back-office "Redis Viewer" tool.
 *
 * Uses the phpredis extension. The connection DSN comes from configuration, falling back to the
 * application's ORO_REDIS_URL. Only read commands are issued (INFO, SCAN, TYPE, TTL, GET, ...).
 */
class RedisInspector
{
    /** Max elements returned for a collection value, and max bytes for a string value. */
    public const int MAX_ELEMENTS = 500;
    public const int MAX_STRING_BYTES = 65536;

    private const int DEFAULT_DB_COUNT = 16;

    public function __construct(
        private readonly string $defaultDsn,
        private readonly ConfigManager $configManager,
    ) {
    }

    /**
     * @return array{
     *     server: array<string, string>,
     *     databases: array<int, array{db: int, keys: int}>
     * }
     */
    public function getOverview(): array
    {
        $redis = $this->connect(0);
        $info = $redis->info();

        $server = [];
        foreach ([
            'redis_version' => 'Version',
            'redis_mode' => 'Mode',
            'os' => 'OS',
            'uptime_in_seconds' => 'Uptime (s)',
            'connected_clients' => 'Clients',
            'used_memory_human' => 'Memory used',
            'maxmemory_human' => 'Max memory',
            'maxmemory_policy' => 'Eviction policy',
            'total_commands_processed' => 'Commands processed',
        ] as $key => $label) {
            if (isset($info[$key]) && (string) $info[$key] !== '') {
                $server[$label] = (string) $info[$key];
            }
        }

        $databases = [];
        $count = $this->databaseCount($redis);
        for ($db = 0; $db < $count; $db++) {
            $redis->select($db);
            $databases[] = ['db' => $db, 'keys' => (int) $redis->dbSize()];
        }

        return ['server' => $server, 'databases' => $databases];
    }

    /**
     * Scans one page of keys (cursor-based, non-blocking).
     *
     * @return array{cursor: string, done: bool, keys: array<int, array{key: string, type: string, ttl: int}>}
     */
    public function scanKeys(int $db, string $pattern, string $cursor, int $count): array
    {
        $redis = $this->connect($db);
        $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_NORETRY);

        $iterator = ($cursor === '' || $cursor === '0') ? null : (int) $cursor;
        $batch = $redis->scan($iterator, $pattern === '' ? '*' : $pattern, max(10, min($count, 1000)));

        $keys = [];
        if (\is_array($batch)) {
            foreach ($batch as $key) {
                $keys[] = [
                    'key' => (string) $key,
                    'type' => $this->typeName($redis->type($key)),
                    'ttl' => (int) $redis->ttl($key),
                ];
            }
        }

        $next = (int) $iterator;

        return ['cursor' => (string) $next, 'done' => $next === 0, 'keys' => $keys];
    }

    /**
     * Returns a key's value (bounded), shaped by its Redis type.
     *
     * @return array<string, mixed>
     */
    public function getValue(int $db, string $key): array
    {
        $redis = $this->connect($db);
        $type = $this->typeName($redis->type($key));
        $ttl = (int) $redis->ttl($key);

        $result = ['key' => $key, 'type' => $type, 'ttl' => $ttl, 'total' => 0, 'truncated' => false];

        switch ($type) {
            case 'string':
                $value = (string) $redis->get($key);
                $length = \strlen($value);
                $result['total'] = $length;
                $result['truncated'] = $length > self::MAX_STRING_BYTES;
                $result['value'] = substr($value, 0, self::MAX_STRING_BYTES);
                break;
            case 'list':
                $result['total'] = (int) $redis->lLen($key);
                $result['items'] = array_map('strval', $redis->lRange($key, 0, self::MAX_ELEMENTS - 1) ?: []);
                $result['truncated'] = $result['total'] > self::MAX_ELEMENTS;
                break;
            case 'set':
                $result['total'] = (int) $redis->sCard($key);
                $result['items'] = array_map('strval', \array_slice($redis->sMembers($key) ?: [], 0, self::MAX_ELEMENTS));
                $result['truncated'] = $result['total'] > self::MAX_ELEMENTS;
                break;
            case 'zset':
                $result['total'] = (int) $redis->zCard($key);
                $items = [];
                foreach ($redis->zRange($key, 0, self::MAX_ELEMENTS - 1, true) ?: [] as $member => $score) {
                    $items[] = ['member' => (string) $member, 'score' => (float) $score];
                }
                $result['items'] = $items;
                $result['truncated'] = $result['total'] > self::MAX_ELEMENTS;
                break;
            case 'hash':
                $result['total'] = (int) $redis->hLen($key);
                $items = [];
                foreach ($redis->hGetAll($key) ?: [] as $field => $value) {
                    if (\count($items) >= self::MAX_ELEMENTS) {
                        break;
                    }
                    $items[] = ['field' => (string) $field, 'value' => (string) $value];
                }
                $result['items'] = $items;
                $result['truncated'] = $result['total'] > self::MAX_ELEMENTS;
                break;
            case 'none':
                $result['missing'] = true;
                break;
            default:
                $result['value'] = '(unsupported type: ' . $type . ')';
        }

        return $result;
    }

    /**
     * @return array{success: bool, message: string, details: array<string, string>}
     */
    public function testConnection(): array
    {
        $dsn = $this->effectiveDsn();
        $parts = parse_url($dsn) ?: [];
        $secret = $this->password($parts);
        $details = [
            'Host' => (string) ($parts['host'] ?? '127.0.0.1'),
            'Port' => (string) ((int) ($parts['port'] ?? 6379)),
        ];

        try {
            $redis = $this->connect(0);
            $info = $redis->info();
            $details['Version'] = (string) ($info['redis_version'] ?? '');
            $details['Memory used'] = (string) ($info['used_memory_human'] ?? '');

            return ['success' => true, 'message' => 'Redis connection succeeded.', 'details' => $details];
        } catch (\Throwable $e) {
            $message = $secret !== '' ? str_replace($secret, '***', $e->getMessage()) : $e->getMessage();

            return ['success' => false, 'message' => 'Redis connection failed: ' . $message, 'details' => $details];
        }
    }

    private function connect(int $db): \Redis
    {
        $parts = parse_url($this->effectiveDsn()) ?: [];
        $host = (string) ($parts['host'] ?? '127.0.0.1');
        $port = (int) ($parts['port'] ?? 6379);

        $redis = new \Redis();
        if (!@$redis->connect($host, $port, 2.0)) {
            throw new \RuntimeException(sprintf('Unable to connect to Redis at %s:%d.', $host, $port));
        }
        $password = $this->password($parts);
        if ($password !== '') {
            $redis->auth($password);
        }
        $redis->select($db);

        return $redis;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function password(array $parts): string
    {
        if (isset($parts['pass'])) {
            return rawurldecode((string) $parts['pass']);
        }
        // redis://password@host (no user/pass separator) puts the secret in "user".
        if (isset($parts['user']) && (string) $parts['user'] !== '') {
            return rawurldecode((string) $parts['user']);
        }

        return '';
    }

    private function databaseCount(\Redis $redis): int
    {
        try {
            $config = $redis->config('GET', 'databases');
            if (\is_array($config) && isset($config['databases'])) {
                return max(1, (int) $config['databases']);
            }
        } catch (\Throwable) {
            // CONFIG may be disabled on managed Redis; fall back to the default.
        }

        return self::DEFAULT_DB_COUNT;
    }

    private function effectiveDsn(): string
    {
        $configured = trim((string) $this->configManager->get('aaxis_devtools.redis_viewer_dsn'));

        return $configured !== '' ? $configured : $this->defaultDsn;
    }

    private function typeName(int|false $type): string
    {
        return match ($type) {
            \Redis::REDIS_STRING => 'string',
            \Redis::REDIS_SET => 'set',
            \Redis::REDIS_LIST => 'list',
            \Redis::REDIS_ZSET => 'zset',
            \Redis::REDIS_HASH => 'hash',
            \Redis::REDIS_STREAM => 'stream',
            default => 'none',
        };
    }
}
