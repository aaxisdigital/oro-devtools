<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Controller;

use Aaxis\Bundle\DevToolsBundle\Controller\AbstractStorageBrowserController;
use Aaxis\Bundle\DevToolsBundle\Controller\FilesystemViewerController;
use Aaxis\Bundle\DevToolsBundle\Storage\FilesystemStorageBrowser;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\ControllerTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The list / preview / raw / download endpoints are implemented once in AbstractStorageBrowserController;
 * exercised here through the Filesystem Viewer controller.
 */
class StorageBrowserControllersTest extends ControllerTestCase
{
    private FilesystemStorageBrowser&MockObject $storage;
    private LoggerInterface&MockObject $logger;
    private FilesystemViewerController $controller;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(FilesystemStorageBrowser::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->controller = new FilesystemViewerController();
        $this->wire($this->controller, [
            FilesystemStorageBrowser::class => $this->storage,
            LoggerInterface::class => $this->logger,
        ]);
    }

    /**
     * @return resource
     */
    private function stream(string $content)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     *
     * @return array{name: string, mime: string, size: int|null, stream: resource}
     */
    private function resource(string $name, string $mime, ?int $size, $stream): array
    {
        return ['name' => $name, 'mime' => $mime, 'size' => $size, 'stream' => $stream];
    }

    private function sent(Response $response): string
    {
        self::assertInstanceOf(StreamedResponse::class, $response);
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    public function testListAddsTheBasePathToTheListing(): void
    {
        $listing = ['path' => '/srv', 'parent' => null, 'entries' => []];
        $this->storage->expects(self::once())->method('listDirectory')->with('/srv')->willReturn($listing);
        $this->storage->method('getStartPath')->willReturn('/srv/app');

        $response = $this->controller->listAction($this->get(['path' => '/srv']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => true] + $listing + ['basePath' => '/srv/app'], $this->decode($response));
    }

    public function testListDefaultsToAnEmptyPath(): void
    {
        $this->storage->expects(self::once())->method('listDirectory')->with('')
            ->willReturn(['path' => '', 'parent' => null, 'entries' => []]);

        $this->controller->listAction($this->get());
    }

    public function testListFailureIsUnprocessable(): void
    {
        $this->storage->method('listDirectory')->willThrowException(new \InvalidArgumentException('Path not found.'));

        $response = $this->controller->listAction($this->get(['path' => '/nope']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Path not found.'], $this->decode($response));
    }

    public function testPreview(): void
    {
        $content = ['name' => 'a.txt', 'path' => '/a.txt', 'size' => 1, 'truncated' => false, 'binary' => false, 'content' => 'x'];
        $this->storage->expects(self::once())->method('readFileContent')->with('/a.txt')->willReturn($content);

        $response = $this->controller->previewAction($this->get(['path' => '/a.txt']));

        self::assertSame(['success' => true] + $content, $this->decode($response));
    }

    public function testPreviewFailureIsLoggedAndUnprocessable(): void
    {
        $error = new \RuntimeException('not readable');
        $this->storage->method('readFileContent')->willThrowException($error);
        $this->logger->expects(self::once())->method('info')
            ->with('Aaxis Tools storage preview failed.', ['exception' => $error]);

        $response = $this->controller->previewAction($this->get(['path' => '/a.txt']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'not readable'], $this->decode($response));
    }

    public function testDownloadStreamsTheFileAsAnAttachment(): void
    {
        $this->storage->method('openResource')->with('/a.bin')
            ->willReturn($this->resource('a.bin', 'application/zip', 5, $this->stream('hello')));

        $response = $this->controller->downloadAction($this->get(['path' => '/a.bin']));

        self::assertSame('hello', $this->sent($response));
        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        self::assertSame('5', $response->headers->get('Content-Length'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('attachment; filename=a.bin', $response->headers->get('Content-Disposition'));
        self::assertNull($response->headers->get('Content-Security-Policy'));
    }

    /**
     * @dataProvider inlineSafeProvider
     */
    public function testRawServesOnlyVettedMediaInline(string $mime): void
    {
        $this->storage->method('openResource')->willReturn($this->resource('pic', $mime, 3, $this->stream('abc')));

        $response = $this->controller->rawAction($this->get(['path' => '/pic']));

        self::assertSame($mime, $response->headers->get('Content-Type'));
        self::assertSame('inline; filename=pic', $response->headers->get('Content-Disposition'));
        self::assertSame('abc', $this->sent($response));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inlineSafeProvider(): iterable
    {
        yield 'png' => ['image/png'];
        yield 'jpeg upper case' => ['IMAGE/JPEG'];
        yield 'pdf' => ['application/pdf'];
        yield 'padded pdf' => [' application/pdf'];
    }

    /**
     * @dataProvider inlineUnsafeProvider
     */
    public function testRawForcesEverythingElseToAnOpaqueDownload(string $mime): void
    {
        $this->storage->method('openResource')->willReturn($this->resource('page', $mime, 3, $this->stream('<b>')));

        $response = $this->controller->rawAction($this->get(['path' => '/page']));

        self::assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=page', $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertNull($response->headers->get('Content-Security-Policy'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inlineUnsafeProvider(): iterable
    {
        yield 'html' => ['text/html'];
        yield 'javascript' => ['application/javascript'];
        yield 'xml' => ['application/xml'];
        yield 'text' => ['text/plain'];
        yield 'looks like an image but is not' => ['application/image/png'];
    }

    public function testInlineSvgIsSandboxedWhenAllowed(): void
    {
        // image/svg+xml passes the image/* allow-list, so the CSP sandbox is the safeguard.
        $this->storage->method('openResource')->willReturn($this->resource('logo.svg', 'image/svg+xml', 5, $this->stream('<svg/>')));

        $response = $this->controller->rawAction($this->get(['path' => '/logo.svg']));

        self::assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        self::assertSame('sandbox', $response->headers->get('Content-Security-Policy'));
        self::assertSame('inline; filename=logo.svg', $response->headers->get('Content-Disposition'));
    }

    public function testDownloadingSvgDoesNotNeedTheSandbox(): void
    {
        $this->storage->method('openResource')->willReturn($this->resource('logo.svg', 'image/svg+xml', 5, $this->stream('<svg/>')));

        $response = $this->controller->downloadAction($this->get(['path' => '/logo.svg']));

        self::assertNull($response->headers->get('Content-Security-Policy'));
        self::assertSame('attachment; filename=logo.svg', $response->headers->get('Content-Disposition'));
    }

    public function testAMissingMimeTypeAndNameFallBackToSafeDefaults(): void
    {
        $this->storage->method('openResource')->willReturn($this->resource('', '', null, $this->stream('')));

        $response = $this->controller->downloadAction($this->get(['path' => '/x']));

        self::assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=download', $response->headers->get('Content-Disposition'));
        self::assertFalse($response->headers->has('Content-Length'));
    }

    public function testStreamingReadsLargerThanOneChunk(): void
    {
        $body = str_repeat('0123456789', 2000);
        $this->storage->method('openResource')->willReturn($this->resource('big', 'application/pdf', \strlen($body), $this->stream($body)));

        self::assertSame($body, $this->sent($this->controller->rawAction($this->get(['path' => '/big']))));
    }

    public function testStreamFailureIsUnprocessable(): void
    {
        $this->storage->method('openResource')->willThrowException(new \InvalidArgumentException('The path is not a file.'));

        $response = $this->controller->rawAction($this->get(['path' => '/dir']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'The path is not a file.'], $this->decode($response));
    }

    public function testSubscribedServicesIncludeTheLoggerAndTheStorage(): void
    {
        $services = FilesystemViewerController::getSubscribedServices();

        self::assertContains(LoggerInterface::class, $services);
        self::assertContains(FilesystemStorageBrowser::class, $services);
        self::assertInstanceOf(AbstractStorageBrowserController::class, $this->controller);
    }
}
