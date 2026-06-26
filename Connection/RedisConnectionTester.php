<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Connection;

use Aaxis\Bundle\CommonBundle\Connection\ConnectionTesterInterface;
use Aaxis\Bundle\DevToolsBundle\Redis\RedisInspector;

/**
 * "Test connection" check for the Redis Viewer.
 *
 * @phpstan-import-type TestResult from ConnectionTesterInterface
 */
class RedisConnectionTester implements ConnectionTesterInterface
{
    public function __construct(private readonly RedisInspector $redisInspector)
    {
    }

    #[\Override]
    public function getTool(): string
    {
        return 'redis_viewer';
    }

    /**
     * @return TestResult
     */
    #[\Override]
    public function test(array $overrides = []): array
    {
        try {
            return $this->redisInspector->testConnection($overrides);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Redis check failed: ' . $e->getMessage(),
                'details' => [],
            ];
        }
    }
}
