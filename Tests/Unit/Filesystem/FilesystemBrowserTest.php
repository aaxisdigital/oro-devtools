<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Filesystem;

use Aaxis\Bundle\DevToolsBundle\Filesystem\FilesystemBrowser;
use PHPUnit\Framework\TestCase;

class FilesystemBrowserTest extends TestCase
{
    private string $root;
    private FilesystemBrowser $browser;

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()) . '/aaxis_fs_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/sub/deeper', 0777, true);
        mkdir($this->root . '/Alpha');
        file_put_contents($this->root . '/b.txt', 'hello');
        file_put_contents($this->root . '/a.txt', 'first');
        file_put_contents($this->root . '/sub/inner.txt', 'inner');
        $this->browser = new FilesystemBrowser($this->root);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->root);
    }

    private function rmTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0644);
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0755);
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->rmTree($path . '/' . $item);
            }
        }
        rmdir($path);
    }

    public function testResolveBasePathUsesTheConfiguredExistingPath(): void
    {
        self::assertSame($this->root . '/sub', $this->browser->resolveBasePath('  ' . $this->root . '/sub  '));
    }

    public function testResolveBasePathFallsBackToTheProjectDirWhenBlank(): void
    {
        self::assertSame($this->root, $this->browser->resolveBasePath(''));
        self::assertSame($this->root, $this->browser->resolveBasePath('   '));
    }

    public function testResolveBasePathFallsBackToTheProjectDirWhenTheConfiguredPathDoesNotExist(): void
    {
        self::assertSame($this->root, $this->browser->resolveBasePath($this->root . '/missing'));
    }

    public function testResolveBasePathReturnsTheRawProjectDirWhenNothingResolves(): void
    {
        $browser = new FilesystemBrowser('/no/such/project/dir');

        self::assertSame('/no/such/project/dir', $browser->resolveBasePath(''));
    }

    public function testListDirectorySortsDirectoriesFirstThenByNameCaseInsensitively(): void
    {
        $listing = $this->browser->listDirectory($this->root, $this->root, true);

        self::assertSame($this->root, $listing['path']);
        self::assertSame(['Alpha', 'sub', 'a.txt', 'b.txt'], array_column($listing['entries'], 'name'));
        self::assertSame(['dir', 'dir', 'file', 'file'], array_column($listing['entries'], 'type'));
    }

    public function testListedEntriesDescribeThemselves(): void
    {
        $entries = array_column($this->browser->listDirectory($this->root, $this->root, true)['entries'], null, 'name');

        $file = $entries['b.txt'];
        self::assertSame($this->root . '/b.txt', $file['path']);
        self::assertSame(5, $file['size']);
        self::assertSame('5 B', $file['sizeFormatted']);
        self::assertGreaterThan(0, $file['modified']);
        self::assertGreaterThan(0, $file['created']);
        self::assertTrue($file['readable']);
        self::assertNotSame('', $file['ownerUser']);
        self::assertNotSame('', $file['ownerGroup']);

        $dir = $entries['sub'];
        self::assertSame(0, $dir['size']);
        self::assertSame('', $dir['sizeFormatted']);
    }

    public function testLargeFilesAreFormattedInHumanUnits(): void
    {
        file_put_contents($this->root . '/big.bin', str_repeat('x', 1536));

        $entries = array_column($this->browser->listDirectory($this->root, $this->root, true)['entries'], null, 'name');

        self::assertSame('1.5 KB', $entries['big.bin']['sizeFormatted']);
    }

    public function testAnEmptyRequestListsTheBasePath(): void
    {
        self::assertSame($this->root, $this->browser->listDirectory('', $this->root, true)['path']);
    }

    public function testTheParentIsOfferedInsideTheBasePath(): void
    {
        $listing = $this->browser->listDirectory($this->root . '/sub', $this->root, true);

        self::assertSame($this->root, $listing['parent']);
    }

    public function testTheParentIsHiddenAtTheBaseWhileRestricted(): void
    {
        self::assertNull($this->browser->listDirectory($this->root, $this->root, true)['parent']);
    }

    public function testTheParentIsOfferedAtTheBaseWhenUnrestricted(): void
    {
        self::assertSame(\dirname($this->root), $this->browser->listDirectory($this->root, $this->root, false)['parent']);
    }

    public function testTheFilesystemRootHasNoParent(): void
    {
        self::assertNull($this->browser->listDirectory('/', '/', false)['parent']);
    }

    /**
     * @dataProvider dotDotProvider
     */
    public function testRelativeSegmentsAreAlwaysRejected(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Relative ".." segments are not allowed.');

        $this->browser->listDirectory($path, $this->root, false);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dotDotProvider(): iterable
    {
        yield 'unix' => ['/tmp/../etc'];
        yield 'leading' => ['../etc'];
        yield 'trailing' => ['/tmp/x/..'];
        yield 'windows separators' => ['C:\\dir\\..\\other'];
        yield 'doubled separators' => ['/a//..//b'];
    }

    public function testDotsInsideANameAreNotRelativeSegments(): void
    {
        file_put_contents($this->root . '/..hidden', 'x');
        file_put_contents($this->root . '/v1..2', 'x');

        self::assertSame('x', $this->browser->readFileContent($this->root . '/..hidden', $this->root, true)['content']);
        self::assertSame('x', $this->browser->readFileContent($this->root . '/v1..2', $this->root, true)['content']);
    }

    public function testAMissingPathIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path not found.');

        $this->browser->listDirectory($this->root . '/nope', $this->root, true);
    }

    public function testListingAFileIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The path is not a directory.');

        $this->browser->listDirectory($this->root . '/a.txt', $this->root, true);
    }

    public function testNavigatingOutsideTheBaseIsRejectedWhileRestricted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Navigation outside the base path is not allowed.');

        $this->browser->listDirectory(\dirname($this->root), $this->root, true);
    }

    public function testASiblingSharingTheBasePrefixIsOutsideTheBase(): void
    {
        $sibling = $this->root . '_evil';
        mkdir($sibling);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Navigation outside the base path is not allowed.');
            $this->browser->listDirectory($sibling, $this->root, true);
        } finally {
            rmdir($sibling);
        }
    }

    public function testASymlinkEscapingTheBaseIsRejectedWhileRestricted(): void
    {
        $outside = realpath(sys_get_temp_dir()) . '/aaxis_fs_outside_' . bin2hex(random_bytes(4));
        mkdir($outside);
        file_put_contents($outside . '/secret.txt', 'secret');
        symlink($outside, $this->root . '/link');

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->browser->readFileContent($this->root . '/link/secret.txt', $this->root, true);
        } finally {
            $this->rmTree($outside);
        }
    }

    public function testTheBasePathItselfMayBeRequestedWithATrailingSeparator(): void
    {
        $listing = $this->browser->listDirectory($this->root . '/sub', $this->root . '/', true);

        self::assertSame($this->root . '/sub', $listing['path']);
    }

    public function testUnrestrictedModeMayLeaveTheBase(): void
    {
        $listing = $this->browser->listDirectory(\dirname($this->root), $this->root, false);

        self::assertSame(\dirname($this->root), $listing['path']);
    }

    public function testResolveReadableFileReturnsTheRealPath(): void
    {
        self::assertSame($this->root . '/a.txt', $this->browser->resolveReadableFile($this->root . '/a.txt', $this->root, true));
    }

    public function testResolveReadableFileRejectsDirectories(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The path is not a file.');

        $this->browser->resolveReadableFile($this->root . '/sub', $this->root, true);
    }

    public function testResolveReadableFileEnforcesTheBase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Navigation outside the base path is not allowed.');

        $this->browser->resolveReadableFile('/etc/hostname', $this->root, true);
    }

    public function testResolveReadableFileRejectsAnUnreadableFile(): void
    {
        $this->skipWhenRoot();
        file_put_contents($this->root . '/locked.txt', 'x');
        chmod($this->root . '/locked.txt', 0000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The file is not readable.');

        $this->browser->resolveReadableFile($this->root . '/locked.txt', $this->root, true);
    }

    public function testListingAnUnreadableDirectoryFails(): void
    {
        $this->skipWhenRoot();
        mkdir($this->root . '/locked');
        chmod($this->root . '/locked', 0000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The directory is not readable.');

        $this->browser->listDirectory($this->root . '/locked', $this->root, true);
    }

    public function testReadFileContentReturnsTextAndMetadata(): void
    {
        $content = $this->browser->readFileContent($this->root . '/b.txt', $this->root, true);

        self::assertSame([
            'name' => 'b.txt',
            'path' => $this->root . '/b.txt',
            'size' => 5,
            'truncated' => false,
            'binary' => false,
            'content' => 'hello',
        ], $content);
    }

    public function testReadFileContentTruncatesLargeFiles(): void
    {
        file_put_contents($this->root . '/large.log', str_repeat('a', FilesystemBrowser::MAX_PREVIEW_BYTES + 10));

        $content = $this->browser->readFileContent($this->root . '/large.log', $this->root, true);

        self::assertTrue($content['truncated']);
        self::assertSame(FilesystemBrowser::MAX_PREVIEW_BYTES + 10, $content['size']);
        self::assertSame(FilesystemBrowser::MAX_PREVIEW_BYTES, \strlen($content['content']));
    }

    public function testAFileOfExactlyTheLimitIsNotTruncated(): void
    {
        file_put_contents($this->root . '/edge.log', str_repeat('a', FilesystemBrowser::MAX_PREVIEW_BYTES));

        self::assertFalse($this->browser->readFileContent($this->root . '/edge.log', $this->root, true)['truncated']);
    }

    /**
     * @dataProvider binaryProvider
     */
    public function testBinaryFilesHaveNoPreviewContent(string $bytes): void
    {
        file_put_contents($this->root . '/blob', $bytes);

        $content = $this->browser->readFileContent($this->root . '/blob', $this->root, true);

        self::assertTrue($content['binary']);
        self::assertSame('', $content['content']);
        self::assertSame(\strlen($bytes), $content['size']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function binaryProvider(): iterable
    {
        yield 'nul byte' => ["ab\0cd"];
        yield 'invalid utf-8' => ["\xff\xfe\xfd"];
    }

    public function testMultibyteTextIsNotBinary(): void
    {
        file_put_contents($this->root . '/utf8.txt', 'Zoë – 日本語');

        $content = $this->browser->readFileContent($this->root . '/utf8.txt', $this->root, true);

        self::assertFalse($content['binary']);
        self::assertSame('Zoë – 日本語', $content['content']);
    }

    public function testAnEmptyFileIsNotBinary(): void
    {
        file_put_contents($this->root . '/empty', '');

        $content = $this->browser->readFileContent($this->root . '/empty', $this->root, true);

        self::assertFalse($content['binary']);
        self::assertSame('', $content['content']);
        self::assertSame(0, $content['size']);
    }

    public function testReadFileContentRejectsDirectories(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The path is not a file.');

        $this->browser->readFileContent($this->root . '/sub', $this->root, true);
    }

    public function testReadFileContentRejectsUnreadableFiles(): void
    {
        $this->skipWhenRoot();
        file_put_contents($this->root . '/locked.txt', 'x');
        chmod($this->root . '/locked.txt', 0000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The file is not readable.');

        $this->browser->readFileContent($this->root . '/locked.txt', $this->root, true);
    }

    private function skipWhenRoot(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('File permissions are not enforced for root.');
        }
    }
}
