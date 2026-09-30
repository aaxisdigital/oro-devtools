<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Controller;

use Aaxis\Bundle\DevToolsBundle\Controller\RedisViewerController;
use Aaxis\Bundle\DevToolsBundle\Redis\RedisInspector;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\ControllerTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class RedisViewerControllerTest extends ControllerTestCase
{
    private RedisInspector&MockObject $inspector;
    private RedisViewerController $controller;

    protected function setUp(): void
    {
        $this->inspector = $this->createMock(RedisInspector::class);
        $this->controller = new RedisViewerController();
        $this->wire($this->controller, [RedisInspector::class => $this->inspector]);
    }

    public function testOverview(): void
    {
        $this->inspector->method('getOverview')->willReturn(['server' => ['Version' => '7'], 'databases' => []]);

        $response = $this->controller->overviewAction();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => true, 'server' => ['Version' => '7'], 'databases' => []], $this->decode($response));
    }

    public function testOverviewFailureIsABadGateway(): void
    {
        $this->inspector->method('getOverview')->willThrowException(new \RuntimeException('Unable to connect'));

        $response = $this->controller->overviewAction();

        self::assertSame(502, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Unable to connect'], $this->decode($response));
    }

    public function testKeysForwardsTheScanParameters(): void
    {
        $this->inspector->expects(self::once())->method('scanKeys')->with(3, 'user:*', '17', 50)
            ->willReturn(['cursor' => '0', 'done' => true, 'keys' => []]);

        $response = $this->controller->keysAction($this->get(['db' => '3', 'pattern' => 'user:*', 'cursor' => '17', 'count' => '50']));

        self::assertSame(['success' => true, 'cursor' => '0', 'done' => true, 'keys' => []], $this->decode($response));
    }

    public function testKeysDefaults(): void
    {
        $this->inspector->expects(self::once())->method('scanKeys')->with(0, '', '', 200)
            ->willReturn(['cursor' => '5', 'done' => false, 'keys' => []]);

        self::assertTrue($this->decode($this->controller->keysAction($this->get()))['success']);
    }

    public function testKeysFailureIsABadGateway(): void
    {
        $this->inspector->method('scanKeys')->willThrowException(new \RuntimeException('down'));

        $response = $this->controller->keysAction($this->get());

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('down', $this->decode($response)['message']);
    }

    public function testValueRequiresAKey(): void
    {
        $this->inspector->expects(self::never())->method('getValue');

        $response = $this->controller->valueAction($this->get(['db' => '1']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'A key is required.'], $this->decode($response));
    }

    public function testValue(): void
    {
        $this->inspector->expects(self::once())->method('getValue')->with(2, 'k')
            ->willReturn(['key' => 'k', 'type' => 'string', 'value' => 'v']);

        $response = $this->controller->valueAction($this->get(['db' => '2', 'key' => 'k']));

        self::assertSame(['success' => true, 'key' => 'k', 'type' => 'string', 'value' => 'v'], $this->decode($response));
    }

    public function testValueFailureIsABadGateway(): void
    {
        $this->inspector->method('getValue')->willThrowException(new \RuntimeException('gone'));

        $response = $this->controller->valueAction($this->get(['key' => 'k']));

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('gone', $this->decode($response)['message']);
    }

    public function testSubscribesToTheInspector(): void
    {
        self::assertContains(RedisInspector::class, RedisViewerController::getSubscribedServices());
    }
}
