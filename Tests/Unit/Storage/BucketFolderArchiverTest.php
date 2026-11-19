<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Storage;

use Aaxis\Bundle\DevToolsBundle\Exception\StorageException;
use Aaxis\Bundle\DevToolsBundle\Storage\BucketFolderArchiver;
use Aaxis\Bundle\DevToolsBundle\Storage\BucketStorageBrowser;
use Aaxis\Bundle\DevToolsBundle\Storage\FolderScanStore;
use Aaxis\Bundle\DevToolsBundle\Storage\FolderZipWriter;
use Aaxis\Bundle\DevToolsBundle\Storage\S3Client;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\InMemoryFileManager;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BucketFolderArchiverTest extends TestCase
{
    private const OWNER = 'user:7';

    private BucketStorageBrowser&MockObject $storage;
    private S3Client&MockObject $s3;
    private MessageProducerInterface&MockObject $producer;
    private InMemoryFileManager $files;
    private BucketFolderArchiver $archiver;

    /** @var array<string, mixed> */
    private array $settings = [];
    private bool $readOnly = false;
    /** @var array<int, array{objects: array<int, array{key: string, size: int}>, nextToken: string}> listing pages, served in order */
    private array $pages = [];
    /** @var array<int, array{string, string, string}> [prefix, token] of every listing call */
    private array $listCalls = [];
    /** @var array<string, string> object key => content */
    private array $objects = [];
    /** @var array<string, array{content: string, type: string}> key => uploaded file */
    private array $uploads = [];
    /** @var string[] keys that already exist (HEAD) */
    private array $existing = [];

    protected function setUp(): void
    {
        $this->s3 = $this->createMock(S3Client::class);
        $this->s3->method('listObjectsPage')->willReturnCallback(function (string $bucket, string $prefix, string $token): array {
            $this->listCalls[] = [$bucket, $prefix, $token];

            return array_shift($this->pages) ?? throw new \LogicException('No listing page left.');
        });
        $this->s3->method('downloadObject')->willReturnCallback(function (string $bucket, string $key, string $file): bool {
            if (!\array_key_exists($key, $this->objects)) {
                return false;
            }
            file_put_contents($file, $this->objects[$key]);

            return true;
        });
        $this->s3->method('putObjectFromFile')->willReturnCallback(function (string $bucket, string $key, string $file, string $type): void {
            $this->uploads[$key] = ['content' => (string) file_get_contents($file), 'type' => $type];
        });
        $this->s3->method('objectExists')->willReturnCallback(fn (string $bucket, string $key): bool => \in_array($key, $this->existing, true));

        $this->storage = $this->createMock(BucketStorageBrowser::class);
        $this->storage->method('client')->willReturn($this->s3);
        $this->storage->method('bucket')->willReturn('media');
        $this->storage->method('isReadOnly')->willReturnCallback(fn (): bool => $this->readOnly);

        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturnCallback(fn (string $key) => $this->settings[$key] ?? null);

        $this->producer = $this->createMock(MessageProducerInterface::class);
        $this->files = new InMemoryFileManager();
        $this->archiver = new BucketFolderArchiver(
            $this->storage,
            new FolderScanStore($this->files),
            new FolderZipWriter(),
            $this->producer,
            $config
        );
    }

    /**
     * @param array<string, int> $sizes key => size
     */
    private function page(array $sizes, string $nextToken = ''): void
    {
        $objects = [];
        foreach ($sizes as $key => $size) {
            $objects[] = ['key' => (string) $key, 'size' => $size];
        }
        $this->pages[] = ['objects' => $objects, 'nextToken' => $nextToken];
    }

    /**
     * @param array<string, string> $objects key => content (one listing page)
     *
     * @return array<string, mixed> the completed scan
     */
    private function scanned(array $objects, string $path = 'r/'): array
    {
        $this->objects += $objects;
        $this->page(array_map('strlen', $objects));

        return $this->archiver->startScan($path, self::OWNER);
    }

    /**
     * @return array<string, string> entry name => content
     */
    private static function zipEntries(string $zipFile): array
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipFile));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $entries;
    }

    private static function zipEntriesOf(string $content): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'zipt');
        file_put_contents($file, $content);
        try {
            return self::zipEntries($file);
        } finally {
            @unlink($file);
        }
    }

    // --- scanning ------------------------------------------------------------------

    public function testScansPageByPageCountingFilesAndBytesButNotFolderMarkers(): void
    {
        $this->page(['r/' => 0, 'r/a.csv' => 100, 'r/sub/' => 0, 'r/sub/b.bin' => 2000], 'tok-2');
        $this->page(['r/sub/c.txt' => 5]);

        $first = $this->archiver->startScan('/r', self::OWNER);

        self::assertFalse($first['complete']);
        self::assertNull($first['mode']);
        self::assertSame(2, $first['files']);
        self::assertSame(2100, $first['bytes']);
        self::assertSame('2.1 KB', $first['bytesFormatted']);
        self::assertSame('r/', $first['path']);
        self::assertSame('r.zip', $first['archiveName']);
        self::assertMatchesRegularExpression('/^r-\d{8}-\d{6}\.zip$/', $first['suggestedFilename']);
        self::assertSame(['files' => 1000, 'bytes' => 5242880, 'bytesFormatted' => '5.0 MB'], $first['limits']);

        $second = $this->archiver->continueScan($first['id'], self::OWNER);

        self::assertSame($first['id'], $second['id']);
        self::assertTrue($second['complete']);
        self::assertSame(BucketFolderArchiver::MODE_DIRECT, $second['mode']);
        self::assertSame(3, $second['files']);
        self::assertSame(2105, $second['bytes']);
        self::assertSame([['media', 'r/', ''], ['media', 'r/', 'tok-2']], $this->listCalls);
    }

    public function testAnEmptyPageWithATokenKeepsTheScanGoing(): void
    {
        $this->page([], 'tok-2');
        $this->page(['k' => 1]);

        $first = $this->archiver->startScan('', self::OWNER);
        $second = $this->archiver->continueScan($first['id'], self::OWNER);

        self::assertFalse($first['complete']);
        self::assertSame(1, $second['files']);
        self::assertSame('media.zip', $second['archiveName'], 'the bucket root is named after the bucket');
    }

    public function testAFinishedScanIsDescribedAgainWithoutListing(): void
    {
        $scan = $this->scanned(['r/a' => 'x']);

        $again = $this->archiver->continueScan($scan['id'], self::OWNER);

        self::assertSame($scan['files'], $again['files']);
        self::assertCount(1, $this->listCalls);
    }

    public function testAnotherUsersScanIsNotFound(): void
    {
        $scan = $this->scanned(['r/a' => 'x']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The folder scan was not found or has expired. Start the folder download again.');

        $this->archiver->continueScan($scan['id'], 'user:8');
    }

    public function testStartingAScanPurgesExpiredOnes(): void
    {
        $stale = str_repeat('d', 32);
        $this->files->files['bucket-archive-' . $stale . '.state.json'] = json_encode(['id' => $stale, 'updatedAt' => 1]);

        $this->scanned(['r/a' => 'x']);

        self::assertArrayNotHasKey('bucket-archive-' . $stale . '.state.json', $this->files->files);
    }

    /**
     * @dataProvider rootNameProvider
     */
    public function testTheArchiveIsNamedAfterTheFolder(string $path, string $expected): void
    {
        $this->page(['x' => 1]);

        self::assertSame($expected, $this->archiver->startScan($path, self::OWNER)['archiveName']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rootNameProvider(): iterable
    {
        yield 'nested folder' => ['exports/2026/', '2026.zip'];
        yield 'folder named 0' => ['a/0/', '0.zip'];
        yield 'dot-dot folder' => ['a/../', 'bucket.zip'];
        yield 'control characters' => ["a/b\x01c/", 'b_c.zip'];
        yield 'backslash' => ['a\\b/', 'a_b.zip'];
    }

    // --- outcome: empty / direct / background ---------------------------------------

    /**
     * @dataProvider modeProvider
     *
     * @param array<string, int> $sizes
     * @param array<string, int> $settings
     */
    public function testChoosesTheOutcomeFromTheConfiguredLimits(array $sizes, array $settings, string $expected): void
    {
        $this->settings = $settings;
        $this->page($sizes);

        self::assertSame($expected, $this->archiver->startScan('r/', self::OWNER)['mode']);
    }

    /**
     * @return iterable<string, array{array<string, int>, array<string, int>, string}>
     */
    public static function modeProvider(): iterable
    {
        $files = static fn (int $n, int $size = 1): array => array_combine(
            array_map(static fn (int $i): string => 'r/f' . $i, range(1, $n)),
            array_fill(0, $n, $size)
        );
        $limits = static fn (int $files, int $mb): array => [
            'aaxis_devtools.bucket_viewer_direct_max_files' => $files,
            'aaxis_devtools.bucket_viewer_direct_max_size_mb' => $mb,
        ];

        yield 'no files' => [['r/' => 0], [], BucketFolderArchiver::MODE_EMPTY];
        yield 'under both limits' => [$files(2, 100), $limits(3, 1), BucketFolderArchiver::MODE_DIRECT];
        yield 'file count reaches the limit' => [$files(3), $limits(3, 1), BucketFolderArchiver::MODE_BACKGROUND];
        yield 'size reaches the limit' => [['r/big' => 1048576], $limits(3, 1), BucketFolderArchiver::MODE_BACKGROUND];
        yield 'just under the size limit' => [['r/big' => 1048575], $limits(3, 1), BucketFolderArchiver::MODE_DIRECT];
        yield 'default limits: 999 files' => [$files(999), [], BucketFolderArchiver::MODE_DIRECT];
        yield 'default limits: 1000 files' => [$files(1000), [], BucketFolderArchiver::MODE_BACKGROUND];
        yield 'non-positive settings fall back to the defaults' => [$files(999), $limits(0, -2), BucketFolderArchiver::MODE_DIRECT];
    }

    public function testTheLimitsComeFromTheBucketViewerSettings(): void
    {
        $this->settings = [
            'aaxis_devtools.bucket_viewer_direct_max_files' => '50',
            'aaxis_devtools.bucket_viewer_direct_max_size_mb' => 20,
        ];

        self::assertSame(['files' => 50, 'bytes' => 20971520], $this->archiver->getDirectDownloadLimits());
    }

    // --- direct download ---------------------------------------------------------------

    public function testBuildsTheZipOfASmallFolder(): void
    {
        $scan = $this->scanned(['r/a.txt' => 'alpha', 'r/sub/b.txt' => 'beta']);

        $archive = $this->archiver->buildDirectArchive($scan['id'], self::OWNER);

        try {
            self::assertSame('r.zip', $archive['name']);
            self::assertSame(['r/a.txt' => 'alpha', 'r/sub/b.txt' => 'beta'], self::zipEntries($archive['file']));
        } finally {
            @unlink($archive['file']);
        }
    }

    public function testTheZipHoldsTheScannedSnapshotEvenIfTheFolderChanged(): void
    {
        $scan = $this->scanned(['r/a.txt' => 'alpha', 'r/gone.txt' => 'x']);
        unset($this->objects['r/gone.txt']);
        $this->objects['r/new.txt'] = 'not scanned';

        $archive = $this->archiver->buildDirectArchive($scan['id'], self::OWNER);

        try {
            self::assertSame(['r/a.txt' => 'alpha'], self::zipEntries($archive['file']));
        } finally {
            @unlink($archive['file']);
        }
    }

    public function testAnUnfinishedScanCannotBeDownloaded(): void
    {
        $this->page(['r/a' => 1], 'more');
        $scan = $this->archiver->startScan('r/', self::OWNER);

        $this->expectExceptionMessage('The folder scan has not finished yet.');

        $this->archiver->buildDirectArchive($scan['id'], self::OWNER);
    }

    public function testAnEmptyFolderCannotBeDownloaded(): void
    {
        $scan = $this->scanned([]);

        $this->expectExceptionMessage('The folder has no files to download.');

        $this->archiver->buildDirectArchive($scan['id'], self::OWNER);
    }

    public function testALargeFolderIsNeverZippedInTheRequest(): void
    {
        $this->settings = ['aaxis_devtools.bucket_viewer_direct_max_files' => 2];
        $scan = $this->scanned(['r/a' => '1', 'r/b' => '2']);

        $this->expectExceptionMessage('The folder is too large for a direct download (limit: under 2 files and under 5.0 MB).');

        $this->archiver->buildDirectArchive($scan['id'], self::OWNER);
    }

    public function testAFailedZipLeavesNoTemporaryFile(): void
    {
        $scan = $this->scanned(['r/a' => '1']);
        $this->objects = [];
        $before = glob(sys_get_temp_dir() . '/aaxis_zip_*') ?: [];

        try {
            $this->archiver->buildDirectArchive($scan['id'], self::OWNER);
            self::fail('Expected an exception.');
        } catch (StorageException $e) {
            self::assertSame('None of the scanned files exist any more.', $e->getMessage());
        }
        self::assertSame($before, glob(sys_get_temp_dir() . '/aaxis_zip_*') ?: []);
    }

    // --- background archive: request ---------------------------------------------------

    public function testQueuesTheBackgroundArchiveUnderTheChosenName(): void
    {
        $scan = $this->scanned(['r/a' => '1']);
        $this->producer->expects(self::once())->method('send')
            ->with('aaxis_devtools.bucket_folder_archive', ['scanId' => $scan['id'], 'targetKey' => 'r/backup.zip']);

        self::assertSame('r/backup.zip', $this->archiver->requestArchive($scan['id'], self::OWNER, ' backup '));
    }

    /**
     * @dataProvider filenameProvider
     */
    public function testTheArchiveNameIsAPlainZipFileName(string $input, string $expectedKey): void
    {
        $scan = $this->scanned(['r/a' => '1']);

        self::assertSame($expectedKey, $this->archiver->requestArchive($scan['id'], self::OWNER, $input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function filenameProvider(): iterable
    {
        yield 'zip kept' => ['all.ZIP', 'r/all.ZIP'];
        yield 'extension added' => ['all.tar', 'r/all.tar.zip'];
        yield 'path stripped' => ['../../etc/x.zip', 'r/x.zip'];
        yield 'windows path stripped' => ['C:\\tmp\\y', 'r/y.zip'];
        yield 'control characters removed' => ["a\nb", 'r/ab.zip'];
        yield 'trailing slash ignored' => ['a/', 'r/a.zip'];
    }

    /**
     * @dataProvider invalidFilenameProvider
     */
    public function testRejectsAMissingFileName(string $input): void
    {
        $scan = $this->scanned(['r/a' => '1']);
        $this->producer->expects(self::never())->method('send');

        $this->expectExceptionMessage('Enter a file name for the archive.');

        $this->archiver->requestArchive($scan['id'], self::OWNER, $input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFilenameProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'dots' => ['..'];
        yield 'slashes only' => ['//'];
    }

    public function testRefusesToOverwriteAnExistingObject(): void
    {
        $scan = $this->scanned(['r/a' => '1']);
        $this->existing = ['r/a.zip'];

        $this->expectExceptionMessage('"a.zip" already exists in this folder. Choose another name.');

        $this->archiver->requestArchive($scan['id'], self::OWNER, 'a');
    }

    public function testRefusesWhileTheBucketIsReadOnly(): void
    {
        $scan = $this->scanned(['r/a' => '1']);
        $this->readOnly = true;

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('The bucket is configured as read-only.');

        $this->archiver->requestArchive($scan['id'], self::OWNER, 'x');
    }

    public function testAnEmptyFolderIsNotArchived(): void
    {
        $scan = $this->scanned([]);

        $this->expectExceptionMessage('The folder has no files to archive.');

        $this->archiver->requestArchive($scan['id'], self::OWNER, 'x');
    }

    public function testAScanIsArchivedOnlyOnce(): void
    {
        $scan = $this->scanned(['r/a' => '1']);
        $this->archiver->requestArchive($scan['id'], self::OWNER, 'x');

        $this->expectExceptionMessage('An archive of this folder scan has already been requested.');

        $this->archiver->requestArchive($scan['id'], self::OWNER, 'y');
    }

    // --- background archive: consumer -------------------------------------------------

    public function testGeneratesAndUploadsTheArchiveThenDiscardsTheScan(): void
    {
        $scan = $this->scanned(['r/a.txt' => 'alpha', 'r/s/b.txt' => 'beta']);
        $key = $this->archiver->requestArchive($scan['id'], self::OWNER, 'all');
        $this->objects['r/all.zip'] = 'appears later';

        self::assertSame(2, $this->archiver->generateArchive($scan['id'], $key));

        self::assertSame('application/zip', $this->uploads['r/all.zip']['type']);
        self::assertSame(['r/a.txt' => 'alpha', 'r/s/b.txt' => 'beta'], self::zipEntriesOf($this->uploads['r/all.zip']['content']));
        self::assertSame([], $this->files->files);
    }

    /**
     * @dataProvider unusableScanProvider
     */
    public function testAnExpiredOrMismatchedScanIsNotArchived(string $target): void
    {
        $scan = $this->scanned(['r/a' => '1']);
        $this->archiver->requestArchive($scan['id'], self::OWNER, 'all');

        $this->expectExceptionMessage('The folder scan has expired. Start the folder download again.');

        $this->archiver->generateArchive($target === 'unknown' ? str_repeat('e', 32) : $scan['id'], $target === 'unknown' ? 'r/all.zip' : $target);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableScanProvider(): iterable
    {
        yield 'unknown scan' => ['unknown'];
        yield 'other target' => ['r/other.zip'];
    }

    public function testABucketTurnedReadOnlyMeanwhileFailsTheJobAndDropsTheScan(): void
    {
        $scan = $this->scanned(['r/a' => '1']);
        $key = $this->archiver->requestArchive($scan['id'], self::OWNER, 'all');
        $this->readOnly = true;

        try {
            $this->archiver->generateArchive($scan['id'], $key);
            self::fail('Expected an exception.');
        } catch (StorageException $e) {
            self::assertSame('The bucket is configured as read-only.', $e->getMessage());
        }
        self::assertSame([], $this->uploads);
        self::assertSame([], $this->files->files);
    }

    public function testReportsAFailureNextToTheExpectedArchive(): void
    {
        $this->archiver->reportFailure('r/all.zip', 'Disk full');

        self::assertSame('text/plain', $this->uploads['r/all.zip.error.txt']['type']);
        self::assertStringStartsWith('The archive "all.zip" could not be generated (', $this->uploads['r/all.zip.error.txt']['content']);
        self::assertStringEndsWith("Reason: Disk full\n", $this->uploads['r/all.zip.error.txt']['content']);
    }
}
