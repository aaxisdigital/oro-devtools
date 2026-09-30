<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support;

/**
 * In-memory stand-in for the phpredis client: answers exactly the read commands RedisInspector
 * issues and records how it was connected/authenticated. Never opens a socket.
 */
final class FakeRedis extends \Redis
{
    public bool $connectResult = true;

    /** @var array{host: string, port: int, timeout: float}|null */
    public ?array $connectedTo = null;

    public mixed $authArgument = null;
    public bool $authCalled = false;

    /** @var int[] */
    public array $selected = [];

    /** @var array<string, mixed> */
    public array $infoResult = [];

    /** @var int[] */
    public array $dbSizes = [];

    /** @var array<string, array{type: int|false, ttl: int, value: mixed}> */
    public array $keys = [];

    /** @var array{next: int, keys: array<int, string>}|false */
    public array|false $scanResult = ['next' => 0, 'keys' => []];

    /** @var array<int, mixed> */
    public array $scanCall = [];

    public mixed $configResult = false;
    public ?\Throwable $configThrows = null;
    public ?\Throwable $infoThrows = null;
    public int $currentDb = 0;

    public function __construct()
    {
        // Deliberately not calling parent::__construct(): no extension state is needed.
    }

    public function connect(
        string $host,
        int $port = 6379,
        float $timeout = 0,
        ?string $persistent_id = null,
        int $retry_interval = 0,
        float $read_timeout = 0,
        ?array $context = null
    ): bool {
        $this->connectedTo = ['host' => $host, 'port' => $port, 'timeout' => $timeout];

        return $this->connectResult;
    }

    public function auth(mixed $credentials): \Redis|bool
    {
        $this->authCalled = true;
        $this->authArgument = $credentials;

        return true;
    }

    public function select(int $db): \Redis|bool
    {
        $this->selected[] = $db;
        $this->currentDb = $db;

        return true;
    }

    public function info(string ...$sections): \Redis|array|false
    {
        if ($this->infoThrows !== null) {
            throw $this->infoThrows;
        }

        return $this->infoResult;
    }

    public function dbSize(): \Redis|int|false
    {
        return $this->dbSizes[$this->currentDb] ?? 0;
    }

    public function config(string $operation, array|string|null $key_or_settings = null, ?string $value = null): mixed
    {
        if ($this->configThrows !== null) {
            throw $this->configThrows;
        }

        return $this->configResult;
    }

    public function setOption(int $option, mixed $value): bool
    {
        return true;
    }

    public function scan(string|int|null &$iterator, ?string $pattern = null, int $count = 0, ?string $type = null): array|false
    {
        $this->scanCall = [$iterator, $pattern, $count];
        if ($this->scanResult === false) {
            return false;
        }
        $iterator = $this->scanResult['next'];

        return $this->scanResult['keys'];
    }

    public function type(string $key): \Redis|int|false
    {
        return $this->keys[$key]['type'] ?? \Redis::REDIS_NOT_FOUND;
    }

    public function ttl(string $key): \Redis|int|false
    {
        return $this->keys[$key]['ttl'] ?? -2;
    }

    public function get(string $key): mixed
    {
        return $this->keys[$key]['value'] ?? false;
    }

    public function lLen(string $key): \Redis|int|false
    {
        return \count($this->keys[$key]['value'] ?? []);
    }

    public function lrange(string $key, int $start, int $end): \Redis|array|false
    {
        return \array_slice($this->keys[$key]['value'] ?? [], $start, $end - $start + 1);
    }

    public function scard(string $key): \Redis|int|false
    {
        return \count($this->keys[$key]['value'] ?? []);
    }

    public function sMembers(string $key): \Redis|array|false
    {
        return $this->keys[$key]['value'] ?? [];
    }

    public function zCard(string $key): \Redis|int|false
    {
        return \count($this->keys[$key]['value'] ?? []);
    }

    public function zRange(string $key, string|int $start, string|int $end, array|bool|null $options = null): \Redis|array|false
    {
        return \array_slice($this->keys[$key]['value'] ?? [], (int) $start, (int) $end - (int) $start + 1, true);
    }

    public function hLen(string $key): \Redis|int|false
    {
        return \count($this->keys[$key]['value'] ?? []);
    }

    public function hGetAll(string $key): \Redis|array|false
    {
        return $this->keys[$key]['value'] ?? [];
    }
}
