<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Controller;

use Aaxis\Bundle\DevToolsBundle\Controller\ElasticViewerController;
use Aaxis\Bundle\DevToolsBundle\Database\ResultExporter;
use Aaxis\Bundle\DevToolsBundle\Elastic\ElasticInspector;
use Aaxis\Bundle\DevToolsBundle\Manager\SavedQueryManager;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\ControllerTestCase;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class ElasticViewerControllerTest extends ControllerTestCase
{
    private ConfigManager&MockObject $config;
    private ElasticInspector&MockObject $inspector;
    private LoggerInterface&MockObject $logger;
    private ElasticViewerController $controller;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigManager::class);
        $this->inspector = $this->createMock(ElasticInspector::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->controller = new ElasticViewerController();
        $this->wire($this->controller, [
            ConfigManager::class => $this->config,
            ElasticInspector::class => $this->inspector,
            LoggerInterface::class => $this->logger,
            ResultExporter::class => new ResultExporter(),
        ]);
    }

    public function testIndices(): void
    {
        $indices = [['name' => 'oro_product', 'docsCount' => 3, 'size' => '1kb', 'health' => 'green']];
        $this->inspector->method('getIndices')->willReturn($indices);

        $response = $this->controller->indicesAction();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['indices' => $indices], $this->decode($response));
    }

    public function testIndicesFailureIsLoggedAndABadGateway(): void
    {
        $error = new \RuntimeException('cluster down');
        $this->inspector->method('getIndices')->willThrowException($error);
        $this->logger->expects(self::once())->method('warning')
            ->with('Aaxis Tools elastic viewer: unable to list indices.', ['exception' => $error]);

        $response = $this->controller->indicesAction();

        self::assertSame(502, $response->getStatusCode());
        self::assertSame(['indices' => [], 'message' => 'cluster down'], $this->decode($response));
    }

    /**
     * @dataProvider emptyQueryPayloadProvider
     */
    public function testAnEmptyQueryIsRejected(string $raw): void
    {
        $this->inspector->expects(self::never())->method('runEsql');

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
        yield 'blank' => ['{"query":"  "}'];
        yield 'missing' => ['{}'];
    }

    public function testQueryReturnsTheResultSet(): void
    {
        $result = ['columns' => ['n'], 'columnTypes' => ['n' => 'long'], 'rows' => [['n' => 1]], 'rowCount' => 1, 'truncated' => false, 'durationMs' => 2];
        $this->inspector->expects(self::once())->method('runEsql')->with('FROM x')->willReturn($result);

        $response = $this->controller->queryAction($this->post(['query' => ' FROM x ']));

        self::assertSame(['success' => true] + $result, $this->decode($response));
    }

    public function testAQueryFailureIsLoggedAndReturnedAsUnprocessable(): void
    {
        $error = new \RuntimeException('plain failure');
        $this->inspector->method('runEsql')->willThrowException($error);
        $this->logger->expects(self::once())->method('info')
            ->with('Aaxis Tools elastic viewer query failed.', ['exception' => $error]);

        $response = $this->controller->queryAction($this->post(['query' => 'FROM x']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'plain failure'], $this->decode($response));
    }

    /**
     * @dataProvider elasticErrorProvider
     */
    public function testVerboseElasticErrorBodiesAreReducedToTheReason(string $body, string $expected): void
    {
        $this->inspector->method('runEsql')->willThrowException(new \RuntimeException($body));

        $response = $this->controller->queryAction($this->post(['query' => 'FROM x']));

        self::assertSame($expected, $this->decode($response)['message']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function elasticErrorProvider(): iterable
    {
        yield 'root cause wins' => [
            '{"error":{"root_cause":[{"reason":"line 1:1: Unknown index [x]"}],"reason":"outer"}}',
            'line 1:1: Unknown index [x]',
        ];
        yield 'outer reason' => ['{"error":{"reason":"outer reason"}}', 'outer reason'];
        yield 'empty reason keeps the body' => ['{"error":{"reason":""}}', '{"error":{"reason":""}}'];
        yield 'non-string reason keeps the body' => ['{"error":{"reason":{"a":1}}}', '{"error":{"reason":{"a":1}}}'];
        yield 'json without an error keeps the body' => ['{"status":500}', '{"status":500}'];
        yield 'not json keeps the message' => ['404 Not Found: nope', '404 Not Found: nope'];
    }

    public function testExportUsesTheElasticToggle(): void
    {
        $this->config->expects(self::once())->method('get')->with('aaxis_devtools.elastic_viewer_allow_export')->willReturn(false);

        $response = $this->controller->exportAction($this->post(['format' => 'json', 'columns' => ['a'], 'rows' => []]));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testExportReturnsTheGeneratedFile(): void
    {
        $this->config->method('get')->willReturn(true);

        $response = $this->controller->exportAction($this->post(['format' => 'json', 'columns' => ['a'], 'rows' => [['a' => 1]]]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function testSubscribesToTheServicesItUses(): void
    {
        $services = ElasticViewerController::getSubscribedServices();

        foreach ([ElasticInspector::class, LoggerInterface::class, SavedQueryManager::class, ResultExporter::class, ConfigManager::class] as $id) {
            self::assertContains($id, $services);
        }
    }
}
