<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Redis;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface;

/**
 * Read-only inspection of a Redis server for the back-office "Redis Viewer" tool.
 *
 * Uses the phpredis extension. The connection DSN comes from configuration, falling back to the
 * application's ORO_REDIS_URL. The DSN is stored in clear (host/port/db, and optionally a username);
 * the password is kept separately in an encrypted config field and applied via AUTH at connect time.
 * Only read commands are issued (INFO, SCAN, TYPE, TTL, GET, ...).
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
        private readonly SymmetricCrypterInterface $crypter,
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
     * Tries to connect using the values currently entered in the config form (even before saving)
     * via $overrides; the password is never returned.
     *
     * @param array<string, string> $overrides values entered in the config form (unsaved)
     *
     * @return array{success: bool, message: string, details: array<string, string>}
     */
    public function testConnection(array $overrides = []): array
    {
        $parts = parse_url($this->effectiveDsn($overrides)) ?: [];
        [, $secret] = $this->credentials($parts, $overrides);
        $details = [
            'Host' => (string) ($parts['host'] ?? '127.0.0.1'),
            'Port' => (string) ((int) ($parts['port'] ?? 6379)),
        ];

        try {
            $redis = $this->connect(0, $overrides);
            $info = $redis->info();
            $details['Version'] = (string) ($info['redis_version'] ?? '');
            $details['Memory used'] = (string) ($info['used_memory_human'] ?? '');

            return ['success' => true, 'message' => 'Redis connection succeeded.', 'details' => $details];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Redis connection failed: ' . $this->redact($e->getMessage(), $secret),
                'details' => $details,
            ];
        }
    }

    /**
     * @param array<string, string> $overrides
     */
    private function connect(int $db, array $overrides = []): \Redis
    {
        $parts = parse_url($this->effectiveDsn($overrides)) ?: [];
        $host = (string) ($parts['host'] ?? '127.0.0.1');
        $port = (int) ($parts['port'] ?? 6379);

        $redis = new \Redis();
        if (!@$redis->connect($host, $port, 2.0)) {
            throw new \RuntimeException(sprintf('Unable to connect to Redis at %s:%d.', $host, $port));
        }
        [$user, $password] = $this->credentials($parts, $overrides);
        if ($password !== '') {
            // Redis 6 ACL needs [username, password]; a bare password authenticates the default user.
            $redis->auth($user !== '' ? [$user, $password] : $password);
        }
        $redis->select($db);

        return $redis;
    }

    /**
     * Resolves the [username, password] to authenticate with. The username (if any) is read from the
     * DSN userinfo; the password is taken — in priority order — from the unsaved form value, the saved
     * encrypted password field, or, for backward compatibility, from a credential embedded in the DSN.
     *
     * @param array<string, mixed> $parts  parse_url() result of the effective DSN
     * @param array<string, string> $overrides
     *
     * @return array{0: string, 1: string} [username, password]
     */
    private function credentials(array $parts, array $overrides): array
    {
        $user = isset($parts['user']) && (string) $parts['user'] !== '' ? rawurldecode((string) $parts['user']) : '';

        $password = $this->enteredSecret($overrides) ?? $this->savedPassword();
        if ($password === '' && isset($parts['pass'])) {
            // Backward compatibility: a password still embedded in the DSN.
            $password = rawurldecode((string) $parts['pass']);
        }
        if ($password === '' && $user !== '') {
            // Legacy redis://password@host (no user/pass separator) put the secret in "user".
            return ['', $user];
        }

        return [$user, $password];
    }

    /**
     * The password typed into the config form (test in edit mode), or null when not entered. The
     * masked placeholder rendered for an already-stored secret (a run of "*") counts as "not entered".
     *
     * @param array<string, string> $overrides
     */
    private function enteredSecret(array $overrides): ?string
    {
        $entered = \array_key_exists('password', $overrides) ? trim((string) $overrides['password']) : '';

        return $entered !== '' && trim($entered, '*') !== '' ? $entered : null;
    }

    /**
     * The saved password, decrypted. Stored encrypted by OroEncodedPlaceholderPasswordType; an unset
     * or undecryptable value yields an empty string.
     */
    private function savedPassword(): string
    {
        $encrypted = trim((string) $this->configManager->get('aaxis_devtools.redis_viewer_password'));
        if ($encrypted === '') {
            return '';
        }

        try {
            return (string) $this->crypter->decryptData($encrypted);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Removes the password from an error message before it is shown to the client: both the literal
     * secret and any credentials embedded in a DSN it may quote (redis://user:pass@host).
     */
    private function redact(string $message, string $secret): string
    {
        if ($secret !== '') {
            $message = str_replace($secret, '***', $message);
        }

        return (string) preg_replace('#://[^@/\s]+@#', '://***@', $message);
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

    /**
     * @param array<string, string> $overrides
     */
    private function effectiveDsn(array $overrides = []): string
    {
        if (\array_key_exists('dsn', $overrides) && trim((string) $overrides['dsn']) !== '') {
            return trim((string) $overrides['dsn']);
        }

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
