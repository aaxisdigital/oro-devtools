<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Connection;

use Aaxis\Bundle\CommonBundle\Connection\ConnectionTesterInterface;
use Oro\Bundle\ElasticSearchBundle\Engine\IndexAgent;

/**
 * "Test connection" check for the Elastic Viewer. Reports the cluster name and ES/Lucene versions.
 *
 * @phpstan-import-type TestResult from ConnectionTesterInterface
 */
class ElasticConnectionTester implements ConnectionTesterInterface
{
    public function __construct(private readonly IndexAgent $indexAgent)
    {
    }

    #[\Override]
    public function getTool(): string
    {
        return 'elastic_viewer';
    }

    /**
     * @return TestResult
     */
    #[\Override]
    public function test(array $overrides = []): array
    {
        try {
            $info = $this->indexAgent->getClient()->info()->asArray();
            $details = [
                'Cluster' => (string) ($info['cluster_name'] ?? ''),
                'Version' => (string) ($info['version']['number'] ?? ''),
                'Lucene' => (string) ($info['version']['lucene_version'] ?? ''),
            ];

            return ['success' => true, 'message' => 'Elasticsearch connection succeeded.', 'details' => $details];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Elasticsearch connection failed: ' . $e->getMessage()
                    . ' Check that Elasticsearch is running and reachable (ORO_SEARCH_URL).',
                'details' => [],
            ];
        }
    }
}
