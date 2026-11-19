<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Controller;

use Aaxis\Bundle\DevToolsBundle\Controller\BucketViewerController;
use Aaxis\Bundle\DevToolsBundle\Exception\StorageException;
use Aaxis\Bundle\DevToolsBundle\Storage\BucketFolderArchiver;
use Aaxis\Bundle\DevToolsBundle\Storage\BucketStorageBrowser;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\ControllerTestCase;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BucketViewerArchiveEndpointsTest extends ControllerTestCase
{
    private BucketFolderArchiver&MockObject $archiver;
    private TokenAccessorInterface&MockObject $tokenAccessor;
    private BucketViewerController $controller;

    protected function setUp(): void
    {
        $this->archiver = $this->createMock(BucketFolderArchiver::class);
        $this->tokenAccessor = $this->createMock(TokenAccessorInterface::class);
        $this->tokenAccessor->method('getUserId')->willReturn(7);
        $this->controller = new BucketViewerController();
        $this->wire($this->controller, [
            BucketStorageBrowser::class => $this->createMock(BucketStorageBrowser::class),
            BucketFolderArchiver::class => $this->archiver,
            TokenAccessorInterface::class => $this->tokenAccessor,
            LoggerInterface::class => $this->createMock(LoggerInterface::class),
        ]);
    }

    public function testSubscribesTheArchiverAndTheTokenAccessor(): void
    {
        $services = BucketViewerController::getSubscribedServices();

        self::assertContains(BucketFolderArchiver::class, $services);
        self::assertContains(TokenAccessorInterface::class, $services);
    }

    // --- scan ------------------------------------------------------------------------

    public function testStartsAScanOfThePathForTheCurrentUser(): void
    {
        $this->archiver->expects(self::once())->method('startScan')->with('reports/', 'user:7')
            ->willReturn(['id' => 'abc', 'files' => 3, 'complete' => false]);
        $this->archiver->expects(self::never())->method('continueScan');

        $response = $this->controller->archiveScanAction($this->post(['path' => 'reports/']));

        self::assertSame(['success' => true, 'id' => 'abc', 'files' => 3, 'complete' => false], $this->decode($response));
    }

    public function testContinuesAScanById(): void
    {
        $this->archiver->expects(self::once())->method('continueScan')->with('abc', 'user:7')
            ->willReturn(['id' => 'abc', 'complete' => true]);
        $this->archiver->expects(self::never())->method('startScan');

        self::assertTrue($this->decode($this->controller->archiveScanAction($this->post(['id' => 'abc', 'path' => 'x'])))['complete']);
    }

    public function testAScanWithoutFieldsStartsAtTheBucketRoot(): void
    {
        $this->archiver->expects(self::once())->method('startScan')->with('', 'user:7')->willReturn(['id' => 'r']);

        $this->controller->archiveScanAction($this->post([]));
    }

    public function testAScanRejectsAnInvalidPayload(): void
    {
        $response = $this->controller->archiveScanAction($this->post(null, 'nope'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Invalid payload.'], $this->decode($response));
    }

    public function testAScanFailureIsReported(): void
    {
        $this->archiver->method('startScan')->willThrowException(new StorageException('Storage error (HTTP 403).'));

        $response = $this->controller->archiveScanAction($this->post(['path' => 'a/']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Storage error (HTTP 403).', $this->decode($response)['message']);
    }

    public function testScansNeedALoggedInUser(): void
    {
        $tokenAccessor = $this->createMock(TokenAccessorInterface::class);
        $tokenAccessor->method('getUserId')->willReturn(null);
        $this->wire($this->controller, [
            BucketFolderArchiver::class => $this->archiver,
            TokenAccessorInterface::class => $tokenAccessor,
        ]);
        $this->archiver->expects(self::never())->method('startScan');

        $response = $this->controller->archiveScanAction($this->post(['path' => 'a/']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('A logged-in user is required.', $this->decode($response)['message']);
    }

    // --- direct download ---------------------------------------------------------------

    public function testStreamsTheZipAndDeletesItAfterwards(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'zipc');
        file_put_contents($file, 'PK zip');
        $this->archiver->expects(self::once())->method('buildDirectArchive')->with('abc', 'user:7')
            ->willReturn(['file' => $file, 'name' => 'relatórios.zip']);

        $response = $this->controller->archiveDownloadAction($this->get(['id' => 'abc']));

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame(
            "attachment; filename=relat__rios.zip; filename*=utf-8''relat%C3%B3rios.zip",
            $response->headers->get('Content-Disposition')
        );

        ob_start();
        $response->sendContent();
        self::assertSame('PK zip', ob_get_clean());
        self::assertFileDoesNotExist($file);
    }

    public function testADownloadThatCannotBeBuiltIsReported(): void
    {
        $this->archiver->method('buildDirectArchive')->willThrowException(new \InvalidArgumentException('The folder scan has not finished yet.'));

        $response = $this->controller->archiveDownloadAction($this->get([]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'The folder scan has not finished yet.'], $this->decode($response));
    }

    // --- background archive ------------------------------------------------------------

    public function testRequestsTheBackgroundArchive(): void
    {
        $this->archiver->expects(self::once())->method('requestArchive')->with('abc', 'user:7', 'all.zip')
            ->willReturn('reports/all.zip');

        $response = $this->controller->archiveGenerateAction($this->post(['id' => 'abc', 'name' => 'all.zip']));

        self::assertSame(['success' => true, 'key' => 'reports/all.zip', 'name' => 'all.zip'], $this->decode($response));
    }

    public function testABackgroundArchiveRequestWithoutFieldsPassesEmptyStrings(): void
    {
        $this->archiver->expects(self::once())->method('requestArchive')->with('', 'user:7', '')
            ->willThrowException(new \InvalidArgumentException('Enter a file name for the archive.'));

        $response = $this->controller->archiveGenerateAction($this->post([]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Enter a file name for the archive.', $this->decode($response)['message']);
    }

    public function testABackgroundArchiveRequestRejectsAnInvalidPayload(): void
    {
        self::assertSame(400, $this->controller->archiveGenerateAction($this->post(null, '[')) ->getStatusCode());
    }

    /**
     * @dataProvider protectedActionProvider
     */
    public function testEndpointsAreCsrfProtectedAndOnlyTheWriteNeedsTheWriteCapability(string $method, bool $csrf, bool $write): void
    {
        $reflection = new \ReflectionMethod(BucketViewerController::class, $method);
        $names = array_map(static fn (\ReflectionAttribute $a): string => $a->getName(), $reflection->getAttributes());

        self::assertSame($csrf, \in_array(\Oro\Bundle\SecurityBundle\Attribute\CsrfProtection::class, $names, true));
        $acl = $reflection->getAttributes(\Oro\Bundle\SecurityBundle\Attribute\AclAncestor::class);
        self::assertSame($write, $acl !== [] && $acl[0]->getArguments() === ['aaxis_devtools_write']);
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function protectedActionProvider(): iterable
    {
        yield 'scan' => ['archiveScanAction', true, false];
        yield 'download' => ['archiveDownloadAction', false, false];
        yield 'generate' => ['archiveGenerateAction', true, true];
    }
}
