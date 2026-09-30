<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Database;

use Aaxis\Bundle\DevToolsBundle\Database\DatabaseInspector;
use Doctrine\DBAL\Cache\ArrayResult;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DatabaseInspectorTest extends TestCase
{
    private Connection&MockObject $connection;
    private DatabaseInspector $inspector;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->inspector = new DatabaseInspector($this->connection);
    }

    /**
     * Routes fetchFirstColumn() by the object type the SQL selects from.
     *
     * @param string[] $views
     * @param string[] $functions
     */
    private function stubCatalog(array $tables, array $views = [], array $functions = []): void
    {
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableNames')->willReturn($tables);
        $this->connection->method('createSchemaManager')->willReturn($schema);
        $this->connection->method('fetchFirstColumn')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'information_schema.views') ? $views : $functions
        );
    }

    public function testTableNamesAreSorted(): void
    {
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableNames')->willReturn(['b_table', 'a_table']);
        $this->connection->method('createSchemaManager')->willReturn($schema);

        self::assertSame(['a_table', 'b_table'], $this->inspector->getTableNames());
    }

    public function testViewNamesAreCastToStrings(): void
    {
        $this->connection->expects(self::once())->method('fetchFirstColumn')
            ->with(self::stringContains('information_schema.views'))->willReturn(['v_one', 7]);

        self::assertSame(['v_one', '7'], $this->inspector->getViewNames());
    }

    public function testFunctionNamesAreCastToStrings(): void
    {
        $this->connection->expects(self::once())->method('fetchFirstColumn')
            ->with(self::stringContains("routine_type = 'FUNCTION'"))->willReturn(['fn_a']);

        self::assertSame(['fn_a'], $this->inspector->getFunctionNames());
    }

    public function testObjectsListTablesThenViewsThenFunctions(): void
    {
        $this->stubCatalog(['t_b', 't_a'], ['v_a'], ['f_a']);

        self::assertSame([
            ['name' => 't_a', 'type' => 'table'],
            ['name' => 't_b', 'type' => 'table'],
            ['name' => 'v_a', 'type' => 'view'],
            ['name' => 'f_a', 'type' => 'function'],
        ], $this->inspector->getDatabaseObjects());
    }

    public function testViewsReportedAmongTablesAreKeptUnderTheViewTypeOnly(): void
    {
        $this->stubCatalog(['orders', 'order_view'], ['order_view']);

        self::assertSame([
            ['name' => 'orders', 'type' => 'table'],
            ['name' => 'order_view', 'type' => 'view'],
        ], $this->inspector->getDatabaseObjects(true, false));
    }

    public function testViewsAndFunctionsCanBeLeftOut(): void
    {
        $this->stubCatalog(['t'], ['v'], ['f']);

        self::assertSame([['name' => 't', 'type' => 'table']], $this->inspector->getDatabaseObjects(false, false));
    }

    public function testViewsAreStillExcludedFromTablesEvenWhenNotListed(): void
    {
        $this->stubCatalog(['t', 'v'], ['v']);

        self::assertSame([['name' => 't', 'type' => 'table']], $this->inspector->getDatabaseObjects(false, false));
    }

    public function testEstimatedRowCountsAreTrustedWhenPositive(): void
    {
        $this->stubCatalog(['big', 'v'], ['v']);
        $this->connection->expects(self::once())->method('fetchAllAssociative')
            ->with(self::stringContains('c.reltuples::bigint'))
            ->willReturn([['name' => 'big', 'val' => '1500']]);
        $this->connection->expects(self::never())->method('fetchOne');

        $objects = $this->inspector->getDatabaseObjects(true, false, DatabaseInspector::COUNT_QUANTITY);

        self::assertSame(['name' => 'big', 'type' => 'table', 'count' => '1500', 'countValue' => 1500], $objects[0]);
        self::assertSame(['name' => 'v', 'type' => 'view'], $objects[1]);
    }

    public function testUnanalyzedTablesGetAnExactCount(): void
    {
        $this->stubCatalog(['fresh', 'never_analyzed']);
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['name' => 'fresh', 'val' => '0'],
            ['name' => 'never_analyzed', 'val' => '-1'],
        ]);
        $this->connection->method('quoteIdentifier')->willReturnCallback(static fn (string $id) => '"' . $id . '"');
        $this->connection->expects(self::exactly(2))->method('fetchOne')->willReturnCallback(
            static fn (string $sql) => str_contains($sql, '"fresh"') ? '4' : '9'
        );

        $objects = $this->inspector->getDatabaseObjects(false, false, DatabaseInspector::COUNT_QUANTITY);

        self::assertSame('4', $objects[0]['count']);
        self::assertSame(4, $objects[0]['countValue']);
        self::assertSame('9', $objects[1]['count']);
    }

    public function testAFailingExactCountFallsBackToZero(): void
    {
        $this->stubCatalog(['locked']);
        $this->connection->method('fetchAllAssociative')->willReturn([['name' => 'locked', 'val' => '0']]);
        $this->connection->method('quoteIdentifier')->willReturnArgument(0);
        $this->connection->method('fetchOne')->willThrowException(new \RuntimeException('permission denied'));

        $objects = $this->inspector->getDatabaseObjects(false, false, DatabaseInspector::COUNT_QUANTITY);

        self::assertSame(['name' => 'locked', 'type' => 'table', 'count' => '0', 'countValue' => 0], $objects[0]);
    }

    public function testByteSizesUseThePrettyPrintedDisplayAndRawValue(): void
    {
        $this->stubCatalog(['t']);
        $this->connection->expects(self::once())->method('fetchAllAssociative')
            ->with(self::stringContains('pg_total_relation_size'))
            ->willReturn([['name' => 't', 'val' => '8192', 'display' => '8192 bytes']]);

        $objects = $this->inspector->getDatabaseObjects(false, false, DatabaseInspector::COUNT_BYTES);

        self::assertSame(['name' => 't', 'type' => 'table', 'count' => '8192 bytes', 'countValue' => 8192], $objects[0]);
    }

    public function testTablesMissingFromTheCountMapCarryNoCount(): void
    {
        $this->stubCatalog(['t']);
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        self::assertSame(
            [['name' => 't', 'type' => 'table']],
            $this->inspector->getDatabaseObjects(false, false, DatabaseInspector::COUNT_BYTES)
        );
    }

    public function testColumnsGetPostgresStyleTypes(): void
    {
        $base = ['udt_name' => 'text', 'is_nullable' => 'NO', 'char_len' => null, 'numeric_precision' => null,
            'numeric_scale' => null, 'datetime_precision' => null];
        $this->connection->expects(self::once())->method('fetchAllAssociative')
            ->with(self::stringContains('information_schema.columns'), ['name' => 'orders'])
            ->willReturn([
                ['column_name' => 'id', 'udt_name' => 'int4', 'numeric_precision' => 32, 'numeric_scale' => 0, 'is_nullable' => 'NO'] + $base,
                ['column_name' => 'title', 'udt_name' => 'varchar', 'char_len' => 255, 'is_nullable' => 'YES'] + $base,
                ['column_name' => 'price', 'udt_name' => 'numeric', 'numeric_precision' => 10, 'numeric_scale' => null] + $base,
                ['column_name' => 'created', 'udt_name' => 'timestamp', 'datetime_precision' => 0] + $base,
                ['column_name' => 'tags', 'udt_name' => '_text'] + $base,
                ['column_name' => 'payload', 'udt_name' => 'jsonb'] + $base,
                ['column_name' => 'odd', 'udt_name' => 'bool', 'datetime_precision' => 3] + $base,
            ]);

        $columns = $this->inspector->getTableColumns('orders');

        self::assertSame([
            ['name' => 'id', 'type' => 'int4(32,0)', 'nullable' => false],
            ['name' => 'title', 'type' => 'varchar(255)', 'nullable' => true],
            ['name' => 'price', 'type' => 'numeric(10,0)', 'nullable' => false],
            ['name' => 'created', 'type' => 'timestamp(0)', 'nullable' => false],
            ['name' => 'tags', 'type' => 'text', 'nullable' => false],
            ['name' => 'payload', 'type' => 'jsonb', 'nullable' => false],
            ['name' => 'odd', 'type' => 'bool', 'nullable' => false],
        ], $columns);
    }

    /**
     * @dataProvider datetimeTypeProvider
     */
    public function testDatetimePrecisionAppliesToTemporalTypesOnly(string $udt, string $expected): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([[
            'column_name' => 'c', 'udt_name' => $udt, 'is_nullable' => 'YES', 'char_len' => null,
            'numeric_precision' => null, 'numeric_scale' => null, 'datetime_precision' => 6,
        ]]);

        self::assertSame($expected, $this->inspector->getTableColumns('t')[0]['type']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function datetimeTypeProvider(): iterable
    {
        yield 'timestamp' => ['timestamp', 'timestamp(6)'];
        yield 'timestamptz' => ['timestamptz', 'timestamptz(6)'];
        yield 'time' => ['time', 'time(6)'];
        yield 'timetz' => ['timetz', 'timetz(6)'];
        yield 'interval' => ['interval', 'interval(6)'];
        yield 'date' => ['date', 'date'];
    }

    public function testAColumnWithoutNullabilityInfoDefaultsToNullable(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([[
            'column_name' => 'c', 'udt_name' => 'text', 'char_len' => null, 'numeric_precision' => null,
            'numeric_scale' => null, 'datetime_precision' => null,
        ]]);

        self::assertTrue($this->inspector->getTableColumns('t')[0]['nullable']);
    }

    /**
     * @dataProvider blankQueryProvider
     */
    public function testAnEmptyQueryIsRejected(string $sql): void
    {
        $this->connection->expects(self::never())->method('beginTransaction');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The query must not be empty.');

        $this->inspector->executeReadOnlyQuery($sql);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankQueryProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ["  \n "];
    }

    public function testRunsInsideAReadOnlyTransactionWithATimeoutAndRollsBack(): void
    {
        $statements = [];
        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            }
        );
        $this->connection->expects(self::once())->method('executeQuery')->with('SELECT id, name FROM t')
            ->willReturn($this->arrayResult([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]));
        $this->connection->expects(self::once())->method('rollBack');
        $this->connection->expects(self::never())->method('commit');

        $result = $this->inspector->executeReadOnlyQuery('  SELECT id, name FROM t  ');

        self::assertSame(['SET TRANSACTION READ ONLY', 'SET LOCAL statement_timeout = 60000'], $statements);
        self::assertSame(['id', 'name'], $result['columns']);
        self::assertSame([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']], $result['rows']);
        self::assertSame(2, $result['rowCount']);
        self::assertFalse($result['truncated']);
        self::assertGreaterThanOrEqual(0, $result['durationMs']);
    }

    public function testAQueryWithoutRowsReturnsNoColumns(): void
    {
        $this->connection->method('executeQuery')->willReturn($this->arrayResult([]));

        $result = $this->inspector->executeReadOnlyQuery('SELECT 1 WHERE false');

        self::assertSame(['columns' => [], 'rows' => [], 'rowCount' => 0, 'truncated' => false], array_diff_key($result, ['durationMs' => 0]));
    }

    public function testTheResultIsCappedAndFlaggedAsTruncated(): void
    {
        $rows = [];
        for ($i = 0; $i < DatabaseInspector::MAX_ROWS + 3; $i++) {
            $rows[] = ['n' => $i];
        }
        $this->connection->method('executeQuery')->willReturn($this->arrayResult($rows));

        $result = $this->inspector->executeReadOnlyQuery('SELECT n FROM big');

        self::assertSame(DatabaseInspector::MAX_ROWS, $result['rowCount']);
        self::assertTrue($result['truncated']);
    }

    public function testExactlyTheCapIsNotTruncated(): void
    {
        $rows = array_map(static fn (int $i) => ['n' => $i], range(1, DatabaseInspector::MAX_ROWS));
        $this->connection->method('executeQuery')->willReturn($this->arrayResult($rows));

        $result = $this->inspector->executeReadOnlyQuery('SELECT n FROM big');

        self::assertSame(DatabaseInspector::MAX_ROWS, $result['rowCount']);
        self::assertFalse($result['truncated']);
    }

    public function testAFailureRollsBackAnActiveTransactionAndRethrows(): void
    {
        $this->connection->method('executeQuery')->willThrowException(new \RuntimeException('cannot execute INSERT in a read-only transaction'));
        $this->connection->method('isTransactionActive')->willReturn(true);
        $this->connection->expects(self::once())->method('rollBack');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('read-only transaction');

        $this->inspector->executeReadOnlyQuery('INSERT INTO t VALUES (1)');
    }

    public function testAFailureAfterTheTransactionEndedDoesNotRollBackTwice(): void
    {
        $this->connection->method('executeQuery')->willThrowException(new \RuntimeException('gone'));
        $this->connection->method('isTransactionActive')->willReturn(false);
        $this->connection->expects(self::never())->method('rollBack');

        $this->expectException(\RuntimeException::class);

        $this->inspector->executeReadOnlyQuery('SELECT 1');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function arrayResult(array $rows): Result
    {
        return new Result(new ArrayResult($rows), $this->connection);
    }
}
