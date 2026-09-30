<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Elastic;

use Aaxis\Bundle\DevToolsBundle\Elastic\ElasticInspector;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\FakeElasticClient;
use Oro\Bundle\ElasticSearchBundle\Engine\IndexAgent;
use PHPUnit\Framework\TestCase;

class ElasticInspectorTest extends TestCase
{
    private FakeElasticClient $client;
    private ElasticInspector $inspector;

    protected function setUp(): void
    {
        $this->client = new FakeElasticClient();
        $agent = $this->createMock(IndexAgent::class);
        $agent->method('getClient')->willReturn($this->client);
        $this->inspector = new ElasticInspector($agent);
    }

    public function testIndicesAreSortedAndSystemIndicesHidden(): void
    {
        $this->client->catIndices = [
            ['index' => 'oro_product', 'docs.count' => '12', 'store.size' => '1.2mb', 'health' => 'green'],
            ['index' => '.kibana', 'docs.count' => '3', 'store.size' => '4kb', 'health' => 'green'],
            ['docs.count' => '3'],
            ['index' => 'oro_customer'],
        ];

        self::assertSame([
            ['name' => 'oro_customer', 'docsCount' => 0, 'size' => '', 'health' => ''],
            ['name' => 'oro_product', 'docsCount' => 12, 'size' => '1.2mb', 'health' => 'green'],
        ], $this->inspector->getIndices());
        self::assertSame(
            ['format' => 'json', 'h' => 'index,docs.count,store.size,health'],
            $this->client->catIndicesParams
        );
    }

    public function testNoIndices(): void
    {
        $this->client->catIndices = [];

        self::assertSame([], $this->inspector->getIndices());
    }

    /**
     * @dataProvider blankQueryProvider
     */
    public function testAnEmptyQueryIsRejectedBeforeReachingTheCluster(string $query): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The query must not be empty.');

        try {
            $this->inspector->runEsql($query);
        } finally {
            self::assertNull($this->client->esqlParams);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankQueryProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ["  \n\t "];
    }

    public function testRunEsqlBuildsGridFriendlyRows(): void
    {
        $this->client->esql = [
            'columns' => [['name' => 'id', 'type' => 'long'], ['name' => 'title', 'type' => 'keyword']],
            'values' => [[1, 'a'], [2, null], [3]],
        ];

        $result = $this->inspector->runEsql('  FROM products | LIMIT 3  ');

        self::assertSame(['body' => ['query' => 'FROM products | LIMIT 3']], $this->client->esqlParams);
        self::assertSame(['id', 'title'], $result['columns']);
        self::assertSame(['id' => 'long', 'title' => 'keyword'], $result['columnTypes']);
        self::assertSame([
            ['id' => 1, 'title' => 'a'],
            ['id' => 2, 'title' => null],
            ['id' => 3, 'title' => null],
        ], $result['rows']);
        self::assertSame(3, $result['rowCount']);
        self::assertFalse($result['truncated']);
        self::assertGreaterThanOrEqual(0, $result['durationMs']);
    }

    public function testRunEsqlToleratesAnEmptyResponse(): void
    {
        $this->client->esql = [];

        $result = $this->inspector->runEsql('FROM nothing');

        self::assertSame([], $result['columns']);
        self::assertSame([], $result['rows']);
        self::assertSame(0, $result['rowCount']);
    }

    public function testColumnsWithoutMetadataFallBackToEmptyStrings(): void
    {
        $this->client->esql = ['columns' => [[]], 'values' => [['x']]];

        $result = $this->inspector->runEsql('FROM x');

        self::assertSame([''], $result['columns']);
        self::assertSame(['' => ''], $result['columnTypes']);
        self::assertSame([['' => 'x']], $result['rows']);
    }

    public function testTheResultSetIsCappedAndFlaggedAsTruncated(): void
    {
        $values = [];
        for ($i = 0; $i < ElasticInspector::MAX_ROWS + 5; $i++) {
            $values[] = [$i];
        }
        $this->client->esql = ['columns' => [['name' => 'n', 'type' => 'integer']], 'values' => $values];

        $result = $this->inspector->runEsql('FROM big');

        self::assertSame(ElasticInspector::MAX_ROWS, $result['rowCount']);
        self::assertCount(ElasticInspector::MAX_ROWS, $result['rows']);
        self::assertTrue($result['truncated']);
        self::assertSame(['n' => ElasticInspector::MAX_ROWS - 1], $result['rows'][ElasticInspector::MAX_ROWS - 1]);
    }

    public function testAResultOfExactlyTheCapIsNotTruncated(): void
    {
        $values = array_map(static fn (int $i) => [$i], range(1, ElasticInspector::MAX_ROWS));
        $this->client->esql = ['columns' => [['name' => 'n', 'type' => 'integer']], 'values' => $values];

        $result = $this->inspector->runEsql('FROM big');

        self::assertSame(ElasticInspector::MAX_ROWS, $result['rowCount']);
        self::assertFalse($result['truncated']);
    }
}
