<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Connection;

use Aaxis\Bundle\CommonBundle\Connection\ConnectionTesterInterface;
use Aaxis\Bundle\DevToolsBundle\Storage\BucketStorageBrowser;

/**
 * "Test connection" check for the Bucket Browser. The test reads the values currently entered in the
 * config form (even before saving) via $overrides; the secret is never returned.
 *
 * @phpstan-import-type TestResult from ConnectionTesterInterface
 */
class BucketConnectionTester implements ConnectionTesterInterface
{
    public function __construct(private readonly BucketStorageBrowser $bucketBrowser)
    {
    }

    #[\Override]
    public function getTool(): string
    {
        return 'bucket_browser';
    }

    /**
     * @return TestResult
     */
    #[\Override]
    public function test(array $overrides = []): array
    {
        try {
            return $this->bucketBrowser->testConnection($overrides);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Bucket check failed: ' . $e->getMessage(),
                'details' => [],
            ];
        }
    }
}
