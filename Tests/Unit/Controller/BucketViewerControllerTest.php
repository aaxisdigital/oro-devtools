<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Controller;

use Aaxis\Bundle\DevToolsBundle\Controller\BucketViewerController;
use Aaxis\Bundle\DevToolsBundle\Storage\BucketStorageBrowser;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\ControllerTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

class BucketViewerControllerTest extends ControllerTestCase
{
    private BucketStorageBrowser&MockObject $storage;
    private BucketViewerController $controller;
    private string $tmp;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(BucketStorageBrowser::class);
        $this->controller = new BucketViewerController();
        $this->wire($this->controller, [
            BucketStorageBrowser::class => $this->storage,
            LoggerInterface::class => $this->createMock(LoggerInterface::class),
        ]);
        $this->tmp = tempnam(sys_get_temp_dir(), 'aaxis_upload_');
        file_put_contents($this->tmp, 'file body');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
    }

    public function testListingAndPreviewUseTheBucketStorage(): void
    {
        $this->storage->expects(self::once())->method('listDirectory')->with('reports/')
            ->willReturn(['path' => 'reports/', 'parent' => '', 'entries' => []]);
        $this->storage->method('getStartPath')->willReturn('');
        $this->storage->expects(self::once())->method('readFileContent')->with('reports/a.csv')
            ->willReturn(['name' => 'a.csv', 'path' => 'reports/a.csv', 'size' => 1, 'truncated' => false, 'binary' => false, 'content' => 'x']);

        $list = $this->decode($this->controller->listAction($this->get(['path' => 'reports/'])));
        $preview = $this->decode($this->controller->previewAction($this->get(['path' => 'reports/a.csv'])));

        self::assertTrue($list['success']);
        self::assertSame('', $list['basePath']);
        self::assertSame('a.csv', $preview['name']);
    }

    public function testRawAndDownloadDispositions(): void
    {
        $stream = fopen('php://temp', 'r+b');
        $this->storage->method('openResource')->willReturn(['name' => 'p.png', 'mime' => 'image/png', 'size' => null, 'stream' => $stream]);

        self::assertSame('inline; filename=p.png', $this->controller->rawAction($this->get(['path' => 'p.png']))->headers->get('Content-Disposition'));
        self::assertSame('attachment; filename=p.png', $this->controller->downloadAction($this->get(['path' => 'p.png']))->headers->get('Content-Disposition'));
    }

    public function testCreateFolder(): void
    {
        $this->storage->expects(self::once())->method('createFolder')->with('reports/', 'new');

        $response = $this->controller->createFolderAction($this->post(['path' => 'reports/', 'name' => 'new']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => true], $this->decode($response));
    }

    public function testCreateFolderWithoutFieldsPassesEmptyStrings(): void
    {
        $this->storage->expects(self::once())->method('createFolder')->with('', '');

        $this->controller->createFolderAction($this->post([]));
    }

    public function testCreateFolderRejectsAnInvalidPayload(): void
    {
        $this->storage->expects(self::never())->method('createFolder');

        $response = $this->controller->createFolderAction($this->post(null, 'nope'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Invalid payload.'], $this->decode($response));
    }

    public function testCreateFolderFailureIsUnprocessable(): void
    {
        $this->storage->method('createFolder')->willThrowException(new \RuntimeException('The bucket is configured as read-only.'));

        $response = $this->controller->createFolderAction($this->post(['path' => '', 'name' => 'x']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('The bucket is configured as read-only.', $this->decode($response)['message']);
    }

    public function testUpload(): void
    {
        $file = new UploadedFile($this->tmp, 'report.txt', 'text/plain', null, true);
        $this->storage->expects(self::once())->method('uploadFile')
            ->with('reports/', $this->tmp, 'report.txt', self::isType('string'));
        $request = new Request([], ['path' => 'reports/'], [], [], ['file' => $file]);

        $response = $this->controller->uploadAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => true], $this->decode($response));
    }

    public function testUploadWithoutAFileIsABadRequest(): void
    {
        $this->storage->expects(self::never())->method('uploadFile');

        $response = $this->controller->uploadAction(new Request());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'No file uploaded.'], $this->decode($response));
    }

    public function testUploadFailureIsUnprocessable(): void
    {
        $file = new UploadedFile($this->tmp, 'report.txt', 'text/plain', null, true);
        $this->storage->method('uploadFile')->willThrowException(new \RuntimeException('Storage error (HTTP 403).'));

        $response = $this->controller->uploadAction(new Request([], [], [], [], ['file' => $file]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Storage error (HTTP 403).', $this->decode($response)['message']);
    }

    /**
     * @dataProvider deleteProvider
     */
    public function testDelete(array $payload, string $path, bool $isDir): void
    {
        $this->storage->expects(self::once())->method('delete')->with($path, $isDir);

        $response = $this->controller->deleteAction($this->post($payload));

        self::assertSame(['success' => true], $this->decode($response));
    }

    /**
     * @return iterable<string, array{array<string, string>, string, bool}>
     */
    public static function deleteProvider(): iterable
    {
        yield 'a folder' => [['path' => 'reports/', 'type' => 'dir'], 'reports/', true];
        yield 'a file' => [['path' => 'reports/a.csv', 'type' => 'file'], 'reports/a.csv', false];
        yield 'no type means file' => [['path' => 'a.csv'], 'a.csv', false];
        yield 'nothing' => [[], '', false];
    }

    public function testDeleteRejectsAnInvalidPayload(): void
    {
        $this->storage->expects(self::never())->method('delete');

        $response = $this->controller->deleteAction($this->post(null, '7'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Invalid payload.', $this->decode($response)['message']);
    }

    public function testDeleteFailureIsUnprocessable(): void
    {
        $this->storage->method('delete')->willThrowException(new \RuntimeException('read-only'));

        $response = $this->controller->deleteAction($this->post(['path' => 'x']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('read-only', $this->decode($response)['message']);
    }

    public function testSubscribesToTheBucketStorage(): void
    {
        $services = BucketViewerController::getSubscribedServices();

        self::assertContains(BucketStorageBrowser::class, $services);
        self::assertContains(LoggerInterface::class, $services);
    }
}
