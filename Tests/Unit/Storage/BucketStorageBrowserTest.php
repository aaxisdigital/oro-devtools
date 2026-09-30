<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Storage;

use Aaxis\Bundle\DevToolsBundle\Storage\BucketStorageBrowser;
use Aaxis\Bundle\DevToolsBundle\Storage\S3Client;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BucketStorageBrowserTest extends TestCase
{
    private ConfigManager&MockObject $config;
    private SymmetricCrypterInterface&MockObject $crypter;
    private S3Client&MockObject $s3;
    /** @var array<string, mixed> */
    private array $settings = [
        'aaxis_devtools.bucket_viewer_url' => 'http://minio:9000',
        'aaxis_devtools.bucket_viewer_user' => 'AKIA',
        'aaxis_devtools.bucket_viewer_name' => 'reports',
        'aaxis_devtools.bucket_viewer_pass' => 'enc',
        'aaxis_devtools.bucket_viewer_read_only' => false,
    ];
    /** @var array<int, array{string, string, string}> */
    private array $clientsCreated = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigManager::class);
        $this->config->method('get')->willReturnCallback(fn (string $key) => $this->settings[$key] ?? null);
        $this->crypter = $this->createMock(SymmetricCrypterInterface::class);
        $this->crypter->method('decryptData')->willReturnCallback(static fn (string $data) => 'dec(' . $data . ')');
        $this->s3 = $this->createMock(S3Client::class);
    }

    private function browser(): BucketStorageBrowser
    {
        return new class ($this->config, $this->crypter, $this) extends BucketStorageBrowser {
            public function __construct(ConfigManager $config, SymmetricCrypterInterface $crypter, private readonly BucketStorageBrowserTest $test)
            {
                parent::__construct($config, $crypter);
            }

            protected function createS3Client(string $url, string $accessKey, string $secretKey): S3Client
            {
                return $this->test->s3For($url, $accessKey, $secretKey);
            }
        };
    }

    /**
     * @internal used by the browser under test
     */
    public function s3For(string $url, string $accessKey, string $secretKey): S3Client
    {
        $this->clientsCreated[] = [$url, $accessKey, $secretKey];

        return $this->s3;
    }

    public function testTheStartPathIsTheBucketRoot(): void
    {
        self::assertSame('', $this->browser()->getStartPath());
    }

    // --- client wiring ---------------------------------------------------------

    public function testTheClientIsBuiltFromTheConfigurationWithADecryptedSecret(): void
    {
        $this->s3->method('listObjects')->willReturn(['prefixes' => [], 'objects' => []]);

        $this->browser()->listDirectory('');

        self::assertSame([['http://minio:9000', 'AKIA', 'dec(enc)']], $this->clientsCreated);
    }

    public function testAMissingEndpointUrlIsReported(): void
    {
        $this->settings['aaxis_devtools.bucket_viewer_url'] = '   ';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The bucket endpoint URL is not configured.');

        $this->browser()->listDirectory('');
    }

    public function testAnUndecryptableSecretMeansAnEmptyOne(): void
    {
        $crypter = $this->createMock(SymmetricCrypterInterface::class);
        $crypter->method('decryptData')->willThrowException(new \RuntimeException('bad'));
        $this->crypter = $crypter;
        $this->s3->method('listObjects')->willReturn(['prefixes' => [], 'objects' => []]);

        $this->browser()->listDirectory('');

        self::assertSame('', $this->clientsCreated[0][2]);
    }

    public function testAnUnsetSecretIsNotDecrypted(): void
    {
        $this->settings['aaxis_devtools.bucket_viewer_pass'] = '  ';
        $this->crypter = $this->createMock(SymmetricCrypterInterface::class);
        $this->crypter->expects(self::never())->method('decryptData');
        $this->s3->method('listObjects')->willReturn(['prefixes' => [], 'objects' => []]);

        $this->browser()->listDirectory('');

        self::assertSame('', $this->clientsCreated[0][2]);
    }

    // --- listing -------------------------------------------------------------

    public function testListingMapsPrefixesToFoldersAndObjectsToFilesSortedFoldersFirst(): void
    {
        $this->s3->expects(self::once())->method('listObjects')->with('reports', '2026/')->willReturn([
            'prefixes' => ['2026/zeta/', '2026/Alpha/'],
            'objects' => [
                ['key' => '2026/b.csv', 'size' => 2048, 'lastModified' => 1700000000],
                ['key' => '2026/A.csv', 'size' => 10, 'lastModified' => 1700000100],
            ],
        ]);

        $listing = $this->browser()->listDirectory(' /2026 ');

        self::assertSame('2026/', $listing['path']);
        self::assertSame('', $listing['parent']);
        self::assertSame(['Alpha', 'zeta', 'A.csv', 'b.csv'], array_column($listing['entries'], 'name'));
        self::assertSame([
            'name' => 'Alpha',
            'type' => 'dir',
            'path' => '2026/Alpha/',
            'size' => 0,
            'sizeFormatted' => '',
            'created' => 0,
            'modified' => 0,
            'ownerUser' => '',
            'ownerGroup' => '',
            'readable' => true,
        ], $listing['entries'][0]);
        self::assertSame([
            'name' => 'b.csv',
            'type' => 'file',
            'path' => '2026/b.csv',
            'size' => 2048,
            'sizeFormatted' => '2.0 KB',
            'created' => 0,
            'modified' => 1700000000,
            'ownerUser' => '',
            'ownerGroup' => '',
            'readable' => true,
        ], $listing['entries'][3]);
    }

    public function testTheRootListingHasNoParent(): void
    {
        $this->s3->expects(self::once())->method('listObjects')->with('reports', '')->willReturn(['prefixes' => [], 'objects' => []]);

        $listing = $this->browser()->listDirectory('');

        self::assertSame(['path' => '', 'parent' => null, 'entries' => []], $listing);
    }

    /**
     * @dataProvider prefixProvider
     */
    public function testPrefixesAreNormalizedAndTheirParentsDerived(string $path, string $prefix, ?string $parent): void
    {
        $this->s3->expects(self::once())->method('listObjects')->with('reports', $prefix)->willReturn(['prefixes' => [], 'objects' => []]);

        $listing = $this->browser()->listDirectory($path);

        self::assertSame($prefix, $listing['path']);
        self::assertSame($parent, $listing['parent']);
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function prefixProvider(): iterable
    {
        yield 'root' => ['', '', null];
        yield 'slash only' => ['/', '', null];
        yield 'blank' => ['   ', '', null];
        yield 'top level folder' => ['a', 'a/', ''];
        yield 'top level folder with slash' => ['/a/', 'a/', ''];
        yield 'nested' => ['a/b/c', 'a/b/c/', 'a/b/'];
        yield 'nested with slash' => ['a/b/', 'a/b/', 'a/'];
    }

    public function testAFolderComesBeforeAFileAndAnEmptyFileHasZeroBytes(): void
    {
        $this->s3->method('listObjects')->willReturn([
            'prefixes' => ['x/'],
            'objects' => [['key' => 'a', 'size' => 0, 'lastModified' => 0]],
        ]);

        $entries = $this->browser()->listDirectory('')['entries'];

        self::assertSame(['dir', 'file'], array_column($entries, 'type'));
        self::assertSame('0 B', $entries[1]['sizeFormatted']);
    }

    // --- reading -------------------------------------------------------------

    public function testAPreviewIsBoundedAndDescribesTheObject(): void
    {
        $this->s3->expects(self::once())->method('getObject')->with('reports', 'dir/a.txt', BucketStorageBrowser::MAX_PREVIEW_BYTES)
            ->willReturn(['status' => 200, 'contentType' => 'text/plain', 'contentLength' => 5, 'body' => 'hello']);

        self::assertSame([
            'name' => 'a.txt',
            'path' => 'dir/a.txt',
            'size' => 5,
            'truncated' => false,
            'binary' => false,
            'content' => 'hello',
        ], $this->browser()->readFileContent('/dir/a.txt'));
    }

    public function testThePreviewSizeFallsBackToTheBodyLengthAndDetectsTruncation(): void
    {
        $this->s3->method('getObject')->willReturn(['status' => 200, 'contentType' => 'text/plain', 'contentLength' => null, 'body' => 'abc']);

        self::assertSame(3, $this->browser()->readFileContent('a.txt')['size']);

        $this->s3 = $this->createMock(S3Client::class);
        $this->s3->method('getObject')->willReturn([
            'status' => 206, 'contentType' => 'text/plain', 'contentLength' => BucketStorageBrowser::MAX_PREVIEW_BYTES + 1, 'body' => 'abc',
        ]);

        self::assertTrue($this->browser()->readFileContent('a.txt')['truncated']);
    }

    /**
     * @dataProvider binaryProvider
     */
    public function testBinaryObjectsHaveNoPreview(string $body): void
    {
        $this->s3->method('getObject')->willReturn(['status' => 200, 'contentType' => 'x', 'contentLength' => \strlen($body), 'body' => $body]);

        $preview = $this->browser()->readFileContent('blob');

        self::assertTrue($preview['binary']);
        self::assertSame('', $preview['content']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function binaryProvider(): iterable
    {
        yield 'nul' => ["a\0b"];
        yield 'invalid utf-8' => ["\xff\xfe"];
    }

    public function testAnEmptyObjectIsNotBinary(): void
    {
        $this->s3->method('getObject')->willReturn(['status' => 200, 'contentType' => 'x', 'contentLength' => 0, 'body' => '']);

        self::assertFalse($this->browser()->readFileContent('empty')['binary']);
    }

    /**
     * @dataProvider notAnObjectProvider
     */
    public function testAFolderIsNotReadable(string $path): void
    {
        $this->s3->expects(self::never())->method('getObject');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The path is not an object.');

        $this->browser()->readFileContent($path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAnObjectProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'slash' => ['/'];
        yield 'folder' => ['a/b/'];
    }

    public function testOpeningAnObjectBuffersItIntoAStream(): void
    {
        $this->s3->expects(self::once())->method('getObject')->with('reports', 'img/logo.png', null)
            ->willReturn(['status' => 200, 'contentType' => 'image/png', 'contentLength' => 4, 'body' => 'PNG!']);

        $resource = $this->browser()->openResource('/img/logo.png');

        self::assertSame('logo.png', $resource['name']);
        self::assertSame('image/png', $resource['mime']);
        self::assertSame(4, $resource['size']);
        self::assertSame('PNG!', stream_get_contents($resource['stream']));
    }

    public function testOpeningRejectsFolders(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The path is not an object.');

        $this->browser()->openResource('a/');
    }

    // --- connection test -----------------------------------------------------

    public function testTheConnectionTestNeedsAnEndpointAndABucket(): void
    {
        $this->settings['aaxis_devtools.bucket_viewer_name'] = '';

        self::assertSame([
            'success' => false,
            'message' => 'Configure the endpoint URL and bucket name first.',
            'details' => [],
        ], $this->browser()->testConnection());
    }

    public function testTheConnectionTestUsesSavedSettings(): void
    {
        $result = ['success' => true, 'message' => 'Connected to the bucket.', 'details' => []];
        $this->s3->expects(self::once())->method('testConnection')->with('reports')->willReturn($result);

        self::assertSame($result, $this->browser()->testConnection());
        self::assertSame([['http://minio:9000', 'AKIA', 'dec(enc)']], $this->clientsCreated);
    }

    public function testTheConnectionTestPrefersTheUnsavedFormValues(): void
    {
        $this->s3->expects(self::once())->method('testConnection')->with('typed-bucket')
            ->willReturn(['success' => true, 'message' => 'ok', 'details' => []]);

        $this->browser()->testConnection(['url' => ' http://typed:9 ', 'user' => 'TYPED', 'name' => 'typed-bucket', 'pass' => 'typed-secret']);

        self::assertSame([['http://typed:9', 'TYPED', 'typed-secret']], $this->clientsCreated);
    }

    /**
     * @dataProvider notEnteredSecretProvider
     */
    public function testBlankOrMaskedFormValuesFallBackToTheSavedOnes(string $typed): void
    {
        $this->s3->method('testConnection')->willReturn(['success' => true, 'message' => 'ok', 'details' => []]);

        $this->browser()->testConnection(['url' => '', 'user' => '  ', 'name' => '', 'pass' => $typed]);

        self::assertSame([['http://minio:9000', 'AKIA', 'dec(enc)']], $this->clientsCreated);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notEnteredSecretProvider(): iterable
    {
        yield 'stars' => ['********'];
        yield 'blank' => ['  '];
        yield 'empty' => [''];
    }

    // --- write operations ----------------------------------------------------

    public function testTheReadOnlyFlagComesFromTheConfiguration(): void
    {
        self::assertFalse($this->browser()->isReadOnly());

        $this->settings['aaxis_devtools.bucket_viewer_read_only'] = 1;

        self::assertTrue($this->browser()->isReadOnly());
    }

    /**
     * @return iterable<string, array{callable(BucketStorageBrowser): void}>
     */
    public static function writeOperationProvider(): iterable
    {
        yield 'create folder' => [static fn (BucketStorageBrowser $b) => $b->createFolder('', 'x')];
        yield 'upload' => [static fn (BucketStorageBrowser $b) => $b->uploadFile('', '/dev/null', 'x', 'text/plain')];
        yield 'delete a file' => [static fn (BucketStorageBrowser $b) => $b->delete('x', false)];
        yield 'delete a folder' => [static fn (BucketStorageBrowser $b) => $b->delete('x/', true)];
    }

    /**
     * @dataProvider writeOperationProvider
     */
    public function testEveryWriteIsRefusedWhenReadOnly(callable $write): void
    {
        $this->settings['aaxis_devtools.bucket_viewer_read_only'] = true;
        $this->s3->expects(self::never())->method('putObject');
        $this->s3->expects(self::never())->method('deleteObject');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The bucket is configured as read-only.');

        $write($this->browser());
    }

    public function testCreatingAFolderWritesAnEmptyDirectoryMarker(): void
    {
        $this->s3->expects(self::once())->method('putObject')->with('reports', 'a/b/new/', '', 'application/x-directory');

        $this->browser()->createFolder('/a/b', ' new ');
    }

    public function testFolderNamesCannotSmuggleSeparators(): void
    {
        $this->s3->expects(self::once())->method('putObject')->with('reports', 'evil/', '', 'application/x-directory');

        $this->browser()->createFolder('', '/e\\v/il');
    }

    /**
     * @dataProvider blankFolderNameProvider
     */
    public function testAFolderNeedsAName(string $name): void
    {
        $this->s3->expects(self::never())->method('putObject');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A folder name is required.');

        $this->browser()->createFolder('a', $name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankFolderNameProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'only separators' => ['/\\/'];
    }

    public function testUploadingWritesTheFileUnderThePrefixWithItsContentType(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'aaxis_b_');
        file_put_contents($tmp, 'payload');
        $this->s3->expects(self::once())->method('putObject')->with('reports', 'in/report.csv', 'payload', 'text/csv');

        try {
            $this->browser()->uploadFile('/in', $tmp, 'C:\\Users\\me\\report.csv', 'text/csv');
        } finally {
            unlink($tmp);
        }
    }

    public function testUploadNamesAreReducedToTheirBasename(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'aaxis_b_');
        $this->s3->expects(self::once())->method('putObject')->with('reports', 'evil.txt', '', 'text/plain');

        try {
            $this->browser()->uploadFile('', $tmp, '../../etc/evil.txt', 'text/plain');
        } finally {
            unlink($tmp);
        }
    }

    public function testUploadNeedsAFileName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A file name is required.');

        $this->browser()->uploadFile('', '/dev/null', '', 'text/plain');
    }

    public function testDeletingAFileRemovesThatObject(): void
    {
        $this->s3->expects(self::once())->method('deleteObject')->with('reports', 'a/b.txt');
        $this->s3->expects(self::never())->method('listAllKeys');

        $this->browser()->delete('/a/b.txt', false);
    }

    public function testDeletingAFolderRemovesEveryKeyUnderItAndTheMarker(): void
    {
        $this->s3->method('listAllKeys')->with('reports', 'a/')->willReturn(['a/one.txt', 'a/sub/two.txt']);
        $deleted = [];
        $this->s3->method('deleteObject')->willReturnCallback(static function (string $bucket, string $key) use (&$deleted): void {
            $deleted[] = [$bucket, $key];
        });

        $this->browser()->delete('a', true);

        self::assertSame([['reports', 'a/one.txt'], ['reports', 'a/sub/two.txt'], ['reports', 'a/']], $deleted);
    }

    public function testTheFolderMarkerIsNotDeletedTwice(): void
    {
        $this->s3->method('listAllKeys')->willReturn(['a/', 'a/one.txt']);
        $this->s3->expects(self::exactly(2))->method('deleteObject');

        $this->browser()->delete('a/', true);
    }
}
