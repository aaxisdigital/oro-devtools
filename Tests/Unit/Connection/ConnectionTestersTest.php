<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Connection;

use Aaxis\Bundle\CommonBundle\Connection\ConnectionTesterInterface;
use Aaxis\Bundle\DevToolsBundle\Connection\BucketConnectionTester;
use Aaxis\Bundle\DevToolsBundle\Connection\ElasticConnectionTester;
use Aaxis\Bundle\DevToolsBundle\Connection\MongoConnectionTester;
use Aaxis\Bundle\DevToolsBundle\Connection\RedisConnectionTester;
use Aaxis\Bundle\DevToolsBundle\Mongo\MongoInspector;
use Aaxis\Bundle\DevToolsBundle\Redis\RedisInspector;
use Aaxis\Bundle\DevToolsBundle\Storage\BucketStorageBrowser;
use Oro\Bundle\ElasticSearchBundle\Engine\IndexAgent;
use PHPUnit\Framework\TestCase;

/**
 * The bucket / mongo / redis testers delegate to their tool's own testConnection() and only add the
 * "never throw" guarantee; the elastic tester queries the cluster info itself.
 */
class ConnectionTestersTest extends TestCase
{
    private const OK = ['success' => true, 'message' => 'fine', 'details' => ['Host' => 'h']];

    public function testBucketTesterIdentifiesTheTool(): void
    {
        self::assertSame('bucket_viewer', (new BucketConnectionTester($this->createMock(BucketStorageBrowser::class)))->getTool());
    }

    public function testBucketTesterForwardsTheFormOverridesAndReturnsTheResult(): void
    {
        $browser = $this->createMock(BucketStorageBrowser::class);
        $browser->expects(self::once())->method('testConnection')->with(['url' => 'http://s3'])->willReturn(self::OK);

        self::assertSame(self::OK, (new BucketConnectionTester($browser))->test(['url' => 'http://s3']));
    }

    public function testBucketTesterTurnsAnExceptionIntoAFailure(): void
    {
        $browser = $this->createMock(BucketStorageBrowser::class);
        $browser->method('testConnection')->willThrowException(new \RuntimeException('boom'));

        self::assertSame(
            ['success' => false, 'message' => 'Bucket check failed: boom', 'details' => []],
            (new BucketConnectionTester($browser))->test()
        );
    }

    public function testMongoTesterIdentifiesTheTool(): void
    {
        self::assertSame('mongodb_viewer', $this->mongoTester($this->createMock(MongoInspector::class))->getTool());
    }

    public function testMongoTesterForwardsTheFormOverridesAndReturnsTheResult(): void
    {
        $inspector = $this->createMock(MongoInspector::class);
        $inspector->expects(self::once())->method('testConnection')->with(['private_dsn' => 'x'])->willReturn(self::OK);

        self::assertSame(self::OK, $this->mongoTester($inspector)->test(['private_dsn' => 'x']));
    }

    public function testMongoTesterTurnsAnExceptionIntoAFailure(): void
    {
        $inspector = $this->createMock(MongoInspector::class);
        $inspector->method('testConnection')->willThrowException(new \RuntimeException('boom'));

        self::assertSame(
            ['success' => false, 'message' => 'MongoDB check failed: boom', 'details' => []],
            $this->mongoTester($inspector)->test()
        );
    }

    public function testRedisTesterIdentifiesTheTool(): void
    {
        self::assertSame('redis_viewer', $this->redisTester($this->createMock(RedisInspector::class))->getTool());
    }

    public function testRedisTesterForwardsTheFormOverridesAndReturnsTheResult(): void
    {
        $inspector = $this->createMock(RedisInspector::class);
        $inspector->expects(self::once())->method('testConnection')->with(['dsn' => 'x'])->willReturn(self::OK);

        self::assertSame(self::OK, $this->redisTester($inspector)->test(['dsn' => 'x']));
    }

    public function testRedisTesterTurnsAnExceptionIntoAFailure(): void
    {
        $inspector = $this->createMock(RedisInspector::class);
        $inspector->method('testConnection')->willThrowException(new \RuntimeException('boom'));

        self::assertSame(
            ['success' => false, 'message' => 'Redis check failed: boom', 'details' => []],
            $this->redisTester($inspector)->test()
        );
    }

    public function testElasticTesterIdentifiesTheTool(): void
    {
        self::assertSame('elastic_viewer', (new ElasticConnectionTester($this->createMock(IndexAgent::class)))->getTool());
    }

    public function testElasticTesterReportsClusterAndVersions(): void
    {
        $tester = new ElasticConnectionTester($this->agent(['cluster_name' => 'oro', 'version' => ['number' => '8.13.0', 'lucene_version' => '9.10.0']]));

        self::assertSame([
            'success' => true,
            'message' => 'Elasticsearch connection succeeded.',
            'details' => ['Cluster' => 'oro', 'Version' => '8.13.0', 'Lucene' => '9.10.0'],
        ], $tester->test());
    }

    public function testElasticTesterToleratesAMinimalInfoPayload(): void
    {
        $result = (new ElasticConnectionTester($this->agent([])))->test();

        self::assertTrue($result['success']);
        self::assertSame(['Cluster' => '', 'Version' => '', 'Lucene' => ''], $result['details']);
    }

    public function testElasticTesterTurnsAnExceptionIntoAFailureWithAHint(): void
    {
        $agent = $this->createMock(IndexAgent::class);
        $agent->method('getClient')->willThrowException(new \RuntimeException('connection refused'));

        $result = (new ElasticConnectionTester($agent))->test();

        self::assertFalse($result['success']);
        self::assertSame(
            'Elasticsearch connection failed: connection refused Check that Elasticsearch is running and reachable (ORO_SEARCH_URL).',
            $result['message']
        );
        self::assertSame([], $result['details']);
    }

    public function testEveryTesterFulfilsTheSharedContract(): void
    {
        self::assertInstanceOf(ConnectionTesterInterface::class, $this->redisTester($this->createMock(RedisInspector::class)));
        self::assertInstanceOf(ConnectionTesterInterface::class, $this->mongoTester($this->createMock(MongoInspector::class)));
    }

    private function mongoTester(MongoInspector $inspector): MongoConnectionTester
    {
        return new MongoConnectionTester($inspector);
    }

    private function redisTester(RedisInspector $inspector): RedisConnectionTester
    {
        return new RedisConnectionTester($inspector);
    }

    /**
     * @param array<string, mixed> $info
     */
    private function agent(array $info): IndexAgent
    {
        // Elastic's client and response classes are final/wide; the tester only needs info()->asArray().
        $client = new class($info) {
            public function __construct(private readonly array $info)
            {
            }

            public function info(): object
            {
                $info = $this->info;

                return new class($info) {
                    public function __construct(private readonly array $info)
                    {
                    }

                    public function asArray(): array
                    {
                        return $this->info;
                    }
                };
            }
        };
        $agent = $this->createMock(IndexAgent::class);
        $agent->method('getClient')->willReturn($client);

        return $agent;
    }
}
