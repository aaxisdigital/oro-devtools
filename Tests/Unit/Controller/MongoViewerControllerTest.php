<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Controller;

use Aaxis\Bundle\DevToolsBundle\Controller\MongoViewerController;
use Aaxis\Bundle\DevToolsBundle\Mongo\MongoInspector;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\ControllerTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class MongoViewerControllerTest extends ControllerTestCase
{
    private MongoInspector&MockObject $inspector;
    private MongoViewerController $controller;

    protected function setUp(): void
    {
        $this->inspector = $this->createMock(MongoInspector::class);
        $this->inspector->method('redactDsnCredentials')
            ->willReturnCallback(static fn (string $text) => str_replace('s3cret', '***', $text));
        $this->controller = new MongoViewerController();
        $this->wire($this->controller, [MongoInspector::class => $this->inspector]);
    }

    public function testOverview(): void
    {
        $this->inspector->method('getOverview')->willReturn(['connections' => []]);

        self::assertSame(['success' => true, 'connections' => []], $this->decode($this->controller->overviewAction()));
    }

    public function testOverviewFailureIsRedactedAndABadGateway(): void
    {
        $this->inspector->method('getOverview')->willThrowException(new \RuntimeException('bad mongodb://u:s3cret@h'));

        $response = $this->controller->overviewAction();

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('bad mongodb://u:***@h', $this->decode($response)['message']);
    }

    public function testCollectionsDefaultToThePrivateConnection(): void
    {
        $this->inspector->expects(self::once())->method('listCollections')->with('private', 'shop')
            ->willReturn([['name' => 'orders', 'count' => 3]]);

        $response = $this->controller->collectionsAction($this->get(['db' => 'shop']));

        self::assertSame(['success' => true, 'collections' => [['name' => 'orders', 'count' => 3]]], $this->decode($response));
    }

    public function testCollectionsHonourThePublicConnection(): void
    {
        $this->inspector->expects(self::once())->method('listCollections')->with('public', 'shop')->willReturn([]);

        $this->controller->collectionsAction($this->get(['db' => 'shop', 'connection' => 'public']));
    }

    public function testAnUnknownConnectionFallsBackToPrivate(): void
    {
        $this->inspector->expects(self::once())->method('listCollections')->with('private', 'shop')->willReturn([]);

        $this->controller->collectionsAction($this->get(['db' => 'shop', 'connection' => 'attacker-host']));
    }

    public function testCollectionsRequireADatabase(): void
    {
        $this->inspector->expects(self::never())->method('listCollections');

        $response = $this->controller->collectionsAction($this->get());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'A database is required.'], $this->decode($response));
    }

    public function testCollectionsFailureIsRedactedAndABadGateway(): void
    {
        $this->inspector->method('listCollections')->willThrowException(new \RuntimeException('auth s3cret failed'));

        $response = $this->controller->collectionsAction($this->get(['db' => 'shop']));

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('auth *** failed', $this->decode($response)['message']);
    }

    public function testDocumentsForwardTheBrowsingParameters(): void
    {
        $this->inspector->expects(self::once())->method('findDocuments')
            ->with('public', 'shop', 'orders', '{"a":1}', 40, 10)
            ->willReturn(['total' => 1, 'documents' => [['a' => 1]], 'truncated' => false]);

        $response = $this->controller->documentsAction($this->get([
            'connection' => 'public', 'db' => 'shop', 'collection' => 'orders', 'filter' => '{"a":1}', 'skip' => '40', 'limit' => '10',
        ]));

        self::assertSame(['success' => true, 'total' => 1, 'documents' => [['a' => 1]], 'truncated' => false], $this->decode($response));
    }

    public function testDocumentsDefaults(): void
    {
        $this->inspector->expects(self::once())->method('findDocuments')
            ->with('private', 'shop', 'orders', '', 0, MongoInspector::DEFAULT_LIMIT)
            ->willReturn(['total' => 0, 'documents' => [], 'truncated' => false]);

        $this->controller->documentsAction($this->get(['db' => 'shop', 'collection' => 'orders']));
    }

    /**
     * @dataProvider missingTargetProvider
     *
     * @param array<string, string> $query
     */
    public function testDocumentsRequireADatabaseAndACollection(array $query): void
    {
        $this->inspector->expects(self::never())->method('findDocuments');

        $response = $this->controller->documentsAction($this->get($query));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('A database and collection are required.', $this->decode($response)['message']);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function missingTargetProvider(): iterable
    {
        yield 'nothing' => [[]];
        yield 'db only' => [['db' => 'shop']];
        yield 'collection only' => [['collection' => 'orders']];
    }

    public function testDocumentsReportAnInvalidFilterAsABadRequest(): void
    {
        $this->inspector->method('findDocuments')->willThrowException(new \InvalidArgumentException('The filter must be a valid JSON object.'));

        $response = $this->controller->documentsAction($this->get(['db' => 'shop', 'collection' => 'orders', 'filter' => '[']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('The filter must be a valid JSON object.', $this->decode($response)['message']);
    }

    public function testDocumentsFailureIsRedactedAndABadGateway(): void
    {
        $this->inspector->method('findDocuments')->willThrowException(new \RuntimeException('down s3cret'));

        $response = $this->controller->documentsAction($this->get(['db' => 'shop', 'collection' => 'orders']));

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('down ***', $this->decode($response)['message']);
    }

    public function testSubscribesToTheInspector(): void
    {
        self::assertContains(MongoInspector::class, MongoViewerController::getSubscribedServices());
    }
}
