<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Controller;

use Aaxis\Bundle\DevToolsBundle\Controller\ConnectionInfoController;
use Aaxis\Bundle\DevToolsBundle\Network\ConnectionInfoInspector;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\ControllerTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;

class ConnectionInfoControllerTest extends ControllerTestCase
{
    private ConnectionInfoInspector&MockObject $inspector;
    private ConnectionInfoController $controller;

    protected function setUp(): void
    {
        $this->inspector = $this->createMock(ConnectionInfoInspector::class);
        $this->controller = new ConnectionInfoController();
        $this->wire($this->controller, [ConnectionInfoInspector::class => $this->inspector]);
    }

    public function testTheIndexResolvesTheClientWithoutAnyTrustedProxy(): void
    {
        $request = new Request();
        $client = ['ip' => '10.0.0.1', 'source' => 'remote_addr', 'via' => null, 'chain' => []];
        $this->inspector->expects(self::once())->method('resolveClientIp')->with($request, [])->willReturn($client);
        $this->inspector->method('getServerInfo')->with($request)->willReturn(['server_addr' => '1.1.1.1']);
        $this->inspector->method('getProxyHeaders')->with($request)->willReturn(['Forwarded' => null]);
        $this->inspector->method('getLocalInterfaces')->willReturn(['lo' => ['127.0.0.1']]);

        self::assertSame([
            'client' => $client,
            'server' => ['server_addr' => '1.1.1.1'],
            'proxyHeaders' => ['Forwarded' => null],
            'interfaces' => ['lo' => ['127.0.0.1']],
        ], $this->controller->indexAction($request));
    }

    public function testResolveAppliesTheSubmittedTrustedProxies(): void
    {
        $request = $this->post(['trustedProxies' => '10.0.0.0/8, bogus']);
        $client = ['ip' => '1.2.3.4', 'source' => 'forwarded', 'via' => '10.0.0.5', 'chain' => []];
        $this->inspector->expects(self::once())->method('parseTrustedProxies')->with('10.0.0.0/8, bogus')->willReturn(['10.0.0.0/8']);
        $this->inspector->expects(self::once())->method('resolveClientIp')->with($request, ['10.0.0.0/8'])->willReturn($client);

        $response = $this->controller->resolveAction($request);

        self::assertSame(['success' => true, 'client' => $client, 'trustedProxies' => ['10.0.0.0/8']], $this->decode($response));
    }

    /**
     * @dataProvider unusablePayloadProvider
     */
    public function testResolveTreatsAnUnusablePayloadAsAnEmptyList(?string $raw): void
    {
        $this->inspector->expects(self::once())->method('parseTrustedProxies')->with('')->willReturn([]);
        $this->inspector->method('resolveClientIp')->willReturn(['ip' => '9.9.9.9']);

        $response = $this->controller->resolveAction($this->post(null, $raw));

        self::assertSame(['success' => true, 'client' => ['ip' => '9.9.9.9'], 'trustedProxies' => []], $this->decode($response));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function unusablePayloadProvider(): iterable
    {
        yield 'empty body' => [''];
        yield 'not json' => ['{oops'];
        yield 'json scalar' => ['"10.0.0.1"'];
        yield 'missing key' => ['{"other":1}'];
    }

    public function testSubscribesToTheInspector(): void
    {
        self::assertContains(ConnectionInfoInspector::class, ConnectionInfoController::getSubscribedServices());
    }
}
