<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Controller;

use Aaxis\Bundle\DevToolsBundle\Controller\NetworkToolsController;
use Aaxis\Bundle\DevToolsBundle\Entity\NetworkTestHistory;
use Aaxis\Bundle\DevToolsBundle\Manager\NetworkTestHistoryManager;
use Aaxis\Bundle\DevToolsBundle\Network\NetworkToolExecutor;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\ControllerTestCase;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class NetworkToolsControllerTest extends ControllerTestCase
{
    private ConfigManager&MockObject $config;
    private NetworkTestHistoryManager&MockObject $history;
    private NetworkToolExecutor&MockObject $executor;
    private LoggerInterface&MockObject $logger;
    private NetworkToolsController $controller;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigManager::class);
        $this->history = $this->createMock(NetworkTestHistoryManager::class);
        $this->executor = $this->createMock(NetworkToolExecutor::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->controller = new NetworkToolsController();
        $this->wire($this->controller, [
            ConfigManager::class => $this->config,
            NetworkTestHistoryManager::class => $this->history,
            NetworkToolExecutor::class => $this->executor,
            LoggerInterface::class => $this->logger,
        ]);
    }

    /**
     * @dataProvider invalidPayloadProvider
     */
    public function testAnInvalidPayloadIsRejected(string $raw, string $message): void
    {
        $this->history->expects(self::never())->method('start');

        $response = $this->controller->runAction($this->post(null, $raw));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'output' => $message], $this->decode($response));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidPayloadProvider(): iterable
    {
        yield 'not json' => ['{nope', 'Invalid payload.'];
        yield 'json scalar' => ['12', 'Invalid payload.'];
        yield 'no tool' => ['{"host":"x"}', 'Unknown tool.'];
        yield 'unknown tool' => ['{"tool":"nmap"}', 'Unknown tool.'];
    }

    public function testADisabledToolIsForbidden(): void
    {
        $this->config->expects(self::once())->method('get')->with('aaxis_devtools.network_tools_ssl_cert')->willReturn(false);
        $this->history->expects(self::never())->method('start');
        $this->executor->expects(self::never())->method('run');

        $response = $this->controller->runAction($this->post(['tool' => 'ssl-cert', 'host' => 'example.org']));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['success' => false, 'output' => 'This tool is disabled.'], $this->decode($response));
    }

    public function testRunsTheToolAndRecordsTheOutcome(): void
    {
        $this->config->method('get')->with('aaxis_devtools.network_tools_dns')->willReturn(true);
        $record = new NetworkTestHistory();
        $this->history->expects(self::once())->method('start')
            ->with('DNS Lookup', ['host' => 'example.org', 'mode' => 'a'])->willReturn($record);
        $this->executor->expects(self::once())->method('run')->with('dns', [
            'host' => 'example.org', 'port' => null, 'path' => '', 'timeout' => null, 'mode' => 'a',
        ])->willReturn('dns example.org');
        $this->history->expects(self::once())->method('finish')
            ->with($record, NetworkTestHistory::RESULT_SUCCESS, self::isType('int'), 'dns example.org');

        $response = $this->controller->runAction($this->post(['tool' => 'dns', 'host' => 'example.org', 'mode' => 'a']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => true, 'output' => 'dns example.org'], $this->decode($response));
    }

    public function testEmptyAndNullParametersAreLeftOutOfTheAuditPayload(): void
    {
        $this->config->method('get')->willReturn(true);
        $this->history->expects(self::once())->method('start')
            ->with('Socket', ['host' => 'db', 'port' => 5432, 'timeout' => 0])->willReturn(new NetworkTestHistory());
        $this->executor->method('run')->willReturn('ok');

        $this->controller->runAction($this->post(['tool' => 'socket', 'host' => 'db', 'port' => 5432, 'timeout' => 0, 'path' => '']));
    }

    public function testTheToolToggleKeyUsesUnderscores(): void
    {
        $this->config->expects(self::once())->method('get')->with('aaxis_devtools.network_tools_ssl_cert')->willReturn(true);
        $this->history->method('start')->with('SSL Certificate', self::anything())->willReturn(new NetworkTestHistory());
        $this->executor->method('run')->willReturn('cert');

        $this->controller->runAction($this->post(['tool' => 'ssl-cert', 'host' => 'example.org']));
    }

    public function testAValidationErrorIsABadRequestAndIsRecorded(): void
    {
        $this->config->method('get')->willReturn(true);
        $record = new NetworkTestHistory();
        $this->history->method('start')->willReturn($record);
        $this->executor->method('run')->willThrowException(new \InvalidArgumentException('A host is required.'));
        $this->history->expects(self::once())->method('finish')
            ->with($record, NetworkTestHistory::RESULT_ERROR, self::isType('int'), 'A host is required.');
        $this->logger->expects(self::never())->method('warning');

        $response = $this->controller->runAction($this->post(['tool' => 'ping']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'output' => 'A host is required.'], $this->decode($response));
    }

    public function testAnUnexpectedErrorIsLoggedAndReturnedAsAServerError(): void
    {
        $this->config->method('get')->willReturn(true);
        $record = new NetworkTestHistory();
        $this->history->method('start')->willReturn($record);
        $error = new \RuntimeException('socket exploded');
        $this->executor->method('run')->willThrowException($error);
        $this->history->expects(self::once())->method('finish')
            ->with($record, NetworkTestHistory::RESULT_ERROR, self::isType('int'), 'Error: socket exploded');
        $this->logger->expects(self::once())->method('warning')
            ->with('Aaxis Tools network tool failed.', ['exception' => $error, 'tool' => 'curl']);

        $response = $this->controller->runAction($this->post(['tool' => 'curl', 'host' => 'example.org']));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['success' => false, 'output' => 'Error: socket exploded'], $this->decode($response));
    }

    public function testEveryExecutorToolHasAnAuditLabel(): void
    {
        self::assertSame(NetworkToolExecutor::TOOLS, array_keys(NetworkToolExecutor::TOOL_LABELS));
    }

    public function testSubscribesToTheServicesItUses(): void
    {
        $services = NetworkToolsController::getSubscribedServices();

        foreach ([NetworkToolExecutor::class, NetworkTestHistoryManager::class, ConfigManager::class, LoggerInterface::class] as $id) {
            self::assertContains($id, $services);
        }
    }
}
