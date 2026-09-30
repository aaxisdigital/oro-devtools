<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Controller;

use Aaxis\Bundle\DevToolsBundle\Controller\DatabaseViewerController;
use Aaxis\Bundle\DevToolsBundle\Database\DatabaseInspector;
use Aaxis\Bundle\DevToolsBundle\Database\ResultExporter;
use Aaxis\Bundle\DevToolsBundle\Entity\QueryHistory;
use Aaxis\Bundle\DevToolsBundle\Manager\QueryHistoryManager;
use Aaxis\Bundle\DevToolsBundle\Manager\SavedQueryManager;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\ControllerTestCase;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class DatabaseViewerControllerTest extends ControllerTestCase
{
    private ConfigManager&MockObject $config;
    private DatabaseInspector&MockObject $inspector;
    private QueryHistoryManager&MockObject $history;
    private LoggerInterface&MockObject $logger;
    private DatabaseViewerController $controller;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigManager::class);
        $this->inspector = $this->createMock(DatabaseInspector::class);
        $this->history = $this->createMock(QueryHistoryManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->controller = new DatabaseViewerController();
        $this->wire($this->controller, [
            ConfigManager::class => $this->config,
            DatabaseInspector::class => $this->inspector,
            QueryHistoryManager::class => $this->history,
            LoggerInterface::class => $this->logger,
            ResultExporter::class => new ResultExporter(),
        ]);
    }

    public function testObjectsUseTheConfiguredVisibilityAndTheRequestedMetric(): void
    {
        $this->config->method('get')->willReturnCallback(static fn (string $key) => match ($key) {
            'aaxis_devtools.database_viewer_show_views' => true,
            'aaxis_devtools.database_viewer_show_functions' => false,
            default => null,
        });
        $this->inspector->expects(self::once())->method('getDatabaseObjects')->with(true, false, 'bytes')
            ->willReturn([['name' => 't', 'type' => 'table']]);

        $response = $this->controller->objectsAction($this->get(['metric' => 'bytes']));

        self::assertSame(['objects' => [['name' => 't', 'type' => 'table']]], $this->decode($response));
    }

    /**
     * @dataProvider metricProvider
     */
    public function testObjectsOnlyAcceptKnownMetrics(?string $given, string $expected): void
    {
        $this->inspector->expects(self::once())->method('getDatabaseObjects')->with(false, false, $expected)->willReturn([]);

        $this->controller->objectsAction($this->get($given === null ? [] : ['metric' => $given]));
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function metricProvider(): iterable
    {
        yield 'default' => [null, 'none'];
        yield 'none' => ['none', 'none'];
        yield 'quantity' => ['quantity', 'quantity'];
        yield 'bytes' => ['bytes', 'bytes'];
        yield 'unknown' => ['everything', 'none'];
    }

    public function testColumns(): void
    {
        $columns = [['name' => 'id', 'type' => 'int4(32,0)', 'nullable' => false]];
        $this->inspector->expects(self::once())->method('getTableColumns')->with('orders')->willReturn($columns);

        $response = $this->controller->columnsAction($this->get(['name' => '  orders ']));

        self::assertSame(['success' => true, 'columns' => $columns], $this->decode($response));
    }

    public function testColumnsRequireATableName(): void
    {
        $this->inspector->expects(self::never())->method('getTableColumns');

        $response = $this->controller->columnsAction($this->get(['name' => '  ']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'A table name is required.'], $this->decode($response));
    }

    /**
     * @dataProvider emptyQueryPayloadProvider
     */
    public function testAnEmptyQueryIsRejectedWithoutRecordingHistory(string $raw): void
    {
        $this->history->expects(self::never())->method('start');
        $this->inspector->expects(self::never())->method('executeReadOnlyQuery');

        $response = $this->controller->queryAction($this->post(null, $raw));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'The query must not be empty.'], $this->decode($response));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyQueryPayloadProvider(): iterable
    {
        yield 'not json' => ['nope'];
        yield 'scalar' => ['5'];
        yield 'blank' => ['{"query":"   "}'];
        yield 'missing' => ['{}'];
    }

    public function testASuccessfulQueryIsRecordedAndReturned(): void
    {
        $record = new QueryHistory();
        $result = ['columns' => ['n'], 'rows' => [['n' => 1]], 'rowCount' => 1, 'truncated' => false, 'durationMs' => 3];
        $this->history->expects(self::once())->method('start')->with('SELECT 1')->willReturn($record);
        $this->inspector->expects(self::once())->method('executeReadOnlyQuery')->with('SELECT 1')->willReturn($result);
        $this->history->expects(self::once())->method('finish')
            ->with($record, QueryHistory::RESULT_SUCCESS, self::isType('int'), 1);

        $response = $this->controller->queryAction($this->post(['query' => '  SELECT 1 ']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => true] + $result, $this->decode($response));
    }

    public function testAWriteAttemptIsRecordedAsBlocked(): void
    {
        $record = new QueryHistory();
        $error = new \RuntimeException('ERROR: cannot execute DELETE in a read-only transaction');
        $this->history->method('start')->willReturn($record);
        $this->inspector->method('executeReadOnlyQuery')->willThrowException($error);
        $this->history->expects(self::once())->method('finish')
            ->with($record, QueryHistory::RESULT_BLOCKED, self::isType('int'), null);
        $this->logger->expects(self::once())->method('info')
            ->with('Aaxis Tools database viewer query failed.', ['exception' => $error]);

        $response = $this->controller->queryAction($this->post(['query' => 'DELETE FROM t']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => $error->getMessage()], $this->decode($response));
    }

    public function testAnyOtherFailureIsRecordedAsAnError(): void
    {
        $record = new QueryHistory();
        $this->history->method('start')->willReturn($record);
        $this->inspector->method('executeReadOnlyQuery')->willThrowException(new \RuntimeException('syntax error at or near "SELEC"'));
        $this->history->expects(self::once())->method('finish')
            ->with($record, QueryHistory::RESULT_ERROR, self::isType('int'), null);

        $response = $this->controller->queryAction($this->post(['query' => 'SELEC 1']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('syntax error at or near "SELEC"', $this->decode($response)['message']);
    }

    // --- export endpoint (ResultExportEndpointTrait) -------------------------

    public function testExportRejectsAnInvalidPayload(): void
    {
        $response = $this->controller->exportAction($this->post(null, 'nope'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Invalid payload.', $this->decode($response)['message']);
    }

    public function testExportIsForbiddenWhenTheToggleIsOff(): void
    {
        $this->config->expects(self::once())->method('get')->with('aaxis_devtools.database_viewer_allow_export')->willReturn(false);

        $response = $this->controller->exportAction($this->post(['format' => 'csv', 'columns' => ['a'], 'rows' => []]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Export is disabled.'], $this->decode($response));
    }

    /**
     * @dataProvider noColumnsProvider
     *
     * @param array<string, mixed> $payload
     */
    public function testExportNeedsAtLeastOneColumn(array $payload): void
    {
        $this->config->method('get')->willReturn(true);

        $response = $this->controller->exportAction($this->post($payload));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Nothing to export.', $this->decode($response)['message']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function noColumnsProvider(): iterable
    {
        yield 'missing' => [['format' => 'csv']];
        yield 'empty' => [['format' => 'csv', 'columns' => []]];
    }

    public function testExportRejectsAnUnsupportedFormat(): void
    {
        $this->config->method('get')->willReturn(true);

        $response = $this->controller->exportAction($this->post(['format' => 'pdf', 'columns' => ['a'], 'rows' => []]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Unsupported export format "pdf".', $this->decode($response)['message']);
    }

    public function testExportReturnsTheGeneratedFile(): void
    {
        $this->config->method('get')->with('aaxis_devtools.database_viewer_allow_export')->willReturn(true);

        $response = $this->controller->exportAction($this->post([
            'format' => 'json', 'columns' => [1, 'b'], 'rows' => ['x' => ['1' => 'one', 'b' => 'bee']],
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame("[\n    {\n        \"1\": \"one\",\n        \"b\": \"bee\"\n    }\n]", $response->getContent());
    }

    public function testSubscribesToTheServicesItUses(): void
    {
        $services = DatabaseViewerController::getSubscribedServices();

        foreach ([
            DatabaseInspector::class, QueryHistoryManager::class, ConfigManager::class, LoggerInterface::class,
            SavedQueryManager::class, ResultExporter::class,
        ] as $id) {
            self::assertContains($id, $services);
        }
    }
}
