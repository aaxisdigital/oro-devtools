<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Storage;

use Aaxis\Bundle\DevToolsBundle\Exception\StorageException;
use Aaxis\Bundle\DevToolsBundle\Storage\FolderZipWriter;
use PHPUnit\Framework\TestCase;

class FolderZipWriterTest extends TestCase
{
    private string $zipFile;
    /** @var array<string, string> object key => content; a missing key "no longer exists" */
    private array $objects = [];
    /** @var string[] */
    private array $localFiles = [];

    protected function setUp(): void
    {
        $this->zipFile = (string) tempnam(sys_get_temp_dir(), 'zipw');
    }

    protected function tearDown(): void
    {
        @unlink($this->zipFile);
    }

    private function download(): \Closure
    {
        return function (string $key, string $localFile): bool {
            $this->localFiles[] = $localFile;
            if (!\array_key_exists($key, $this->objects)) {
                return false;
            }
            file_put_contents($localFile, $this->objects[$key]);

            return true;
        };
    }

    /**
     * @return array<string, array{content: string, method: int}>
     */
    private function entries(): array
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->zipFile));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = (array) $zip->statIndex($i);
            $entries[$stat['name']] = ['content' => (string) $zip->getFromIndex($i), 'method' => $stat['comp_method']];
        }
        $zip->close();

        return $entries;
    }

    public function testZipsTheFilesUnderTheRootFolderKeepingSubFolders(): void
    {
        $this->objects = ['r/a.csv' => str_repeat('a,b,c' . "\n", 50), 'r/sub/deep/b.txt' => 'bee', 'r/pic.PNG' => 'png bytes'];

        $added = (new FolderZipWriter())->write($this->zipFile, 'reports', 'r/', array_keys($this->objects), $this->download());

        self::assertSame(3, $added);
        $entries = $this->entries();
        self::assertSame(['reports/a.csv', 'reports/sub/deep/b.txt', 'reports/pic.PNG'], array_keys($entries));
        self::assertSame('bee', $entries['reports/sub/deep/b.txt']['content']);
        self::assertSame(\ZipArchive::CM_DEFLATE, $entries['reports/a.csv']['method']);
        self::assertSame(\ZipArchive::CM_STORE, $entries['reports/pic.PNG']['method'], 'already-compressed formats are stored');
        foreach ($this->localFiles as $local) {
            self::assertFileDoesNotExist($local, 'temporary copies are removed');
        }
    }

    public function testSkipsObjectsThatNoLongerExist(): void
    {
        $this->objects = ['a' => '1'];

        $added = (new FolderZipWriter())->write($this->zipFile, 'root', '', ['a', 'gone'], $this->download());

        self::assertSame(1, $added);
        self::assertSame(['root/a'], array_keys($this->entries()));
    }

    public function testFailsWhenNothingIsLeftToZip(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('None of the scanned files exist any more.');

        (new FolderZipWriter())->write($this->zipFile, 'root', '', ['gone', 'r/..'], $this->download());
    }

    public function testADownloadErrorAbortsWithoutLeavingTemporaryFiles(): void
    {
        $this->objects = ['a' => '1'];
        $calls = 0;
        $download = function (string $key, string $localFile) use (&$calls): bool {
            $this->localFiles[] = $localFile;
            if (++$calls === 2) {
                throw new StorageException('boom');
            }
            file_put_contents($localFile, 'x');

            return true;
        };

        try {
            (new FolderZipWriter())->write($this->zipFile, 'root', '', ['a', 'b'], $download);
            self::fail('Expected an exception.');
        } catch (StorageException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertFileDoesNotExist(\dirname($this->localFiles[0]));
    }

    public function testAnArchivePathThatCannotBeOpenedIsReported(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Unable to create the archive file.');

        (new FolderZipWriter())->write(sys_get_temp_dir(), 'r', '', ['a'], $this->download());
    }

    public function testAnArchiveThatCannotBeWrittenIsReported(): void
    {
        $this->objects = ['a' => '1'];

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Unable to write the archive: Failure to create temporary file');

        (new FolderZipWriter())->write(sys_get_temp_dir() . '/no-such-dir-' . uniqid() . '/x.zip', 'r', '', ['a'], $this->download());
    }

    /**
     * @dataProvider entryNameProvider
     */
    public function testEntryNamesStayInsideTheRootFolder(string $prefix, string $key, ?string $expected): void
    {
        self::assertSame($expected, FolderZipWriter::entryName('root', $prefix, $key));
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function entryNameProvider(): iterable
    {
        yield 'below the prefix' => ['a/', 'a/b/c.txt', 'root/b/c.txt'];
        yield 'bucket root' => ['', 'x.txt', 'root/x.txt'];
        yield 'dot-dot segments dropped' => ['a/', 'a/../../etc/passwd', 'root/etc/passwd'];
        yield 'backslashes are separators' => ['a/', 'a/..\\..\\win.ini', 'root/win.ini'];
        yield 'empty and dot segments dropped' => ['a/', 'a//./x', 'root/x'];
        yield 'nothing left' => ['a/', 'a/..', null];
        yield 'outside the prefix (defensive)' => ['a/', 'z/y', 'root/z/y'];
    }
}
