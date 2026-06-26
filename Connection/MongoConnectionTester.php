<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Connection;

use Aaxis\Bundle\CommonBundle\Connection\ConnectionTesterInterface;
use Aaxis\Bundle\DevToolsBundle\Mongo\MongoInspector;

/**
 * "Test connection" check for the MongoDB Viewer.
 *
 * @phpstan-import-type TestResult from ConnectionTesterInterface
 */
class MongoConnectionTester implements ConnectionTesterInterface
{
    public function __construct(private readonly MongoInspector $mongoInspector)
    {
    }

    #[\Override]
    public function getTool(): string
    {
        return 'mongodb_viewer';
    }

    /**
     * @return TestResult
     */
    #[\Override]
    public function test(array $overrides = []): array
    {
        try {
            return $this->mongoInspector->testConnection($overrides);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'MongoDB check failed: ' . $e->getMessage(),
                'details' => [],
            ];
        }
    }
}
