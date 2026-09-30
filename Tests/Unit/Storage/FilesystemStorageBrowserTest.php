<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Storage;

use Aaxis\Bundle\DevToolsBundle\Exception\StorageException;
use Aaxis\Bundle\DevToolsBundle\Filesystem\FilesystemBrowser;
use Aaxis\Bundle\DevToolsBundle\Storage\FilesystemStorageBrowser;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FilesystemStorageBrowserTest extends TestCase
{
    private FilesystemBrowser&MockObject $browser;
    private ConfigManager&MockObject $config;
    private FilesystemStorageBrowser $storage;
    /** @var string[] */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->browser = $this->createMock(FilesystemBrowser::class);
        $this->config = $this->createMock(ConfigManager::class);
        $this->config->method('get')->willReturnCallback(static fn (string $key) => match ($key) {
            'aaxis_devtools.filesystem_viewer_base_path' => '/configured',
            'aaxis_devtools.filesystem_viewer_restricted' => 1,
            default => null,
        });
        $this->browser->method('resolveBasePath')->willReturnCallback(static fn (string $configured) => '/real' . $configured);
        $this->storage = new FilesystemStorageBrowser($this->browser, $this->config);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
    }

    private function tmp(string $content, string $suffix = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'aaxis_fsb_');
        if ($suffix !== '') {
            rename($path, $path .= $suffix);
        }
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        return $path;
    }

    public function testTheStartPathIsTheResolvedConfiguredBasePath(): void
    {
        self::assertSame('/real/configured', $this->storage->getStartPath());
    }

    public function testListingDelegatesWithTheBasePathAndRestrictionFlag(): void
    {
        $listing = ['path' => '/real/configured/x', 'parent' => null, 'entries' => []];
        $this->browser->expects(self::once())->method('listDirectory')->with('/x', '/real/configured', true)->willReturn($listing);

        self::assertSame($listing, $this->storage->listDirectory('/x'));
    }

    public function testPreviewDelegatesWithTheBasePathAndRestrictionFlag(): void
    {
        $preview = ['name' => 'a', 'path' => '/a', 'size' => 0, 'truncated' => false, 'binary' => false, 'content' => ''];
        $this->browser->expects(self::once())->method('readFileContent')->with('/a', '/real/configured', true)->willReturn($preview);

        self::assertSame($preview, $this->storage->readFileContent('/a'));
    }

    public function testAnUnsetRestrictionMeansUnrestricted(): void
    {
        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturn(null);
        $browser = $this->createMock(FilesystemBrowser::class);
        $browser->method('resolveBasePath')->willReturn('/base');
        $browser->expects(self::once())->method('listDirectory')->with('', '/base', false)
            ->willReturn(['path' => '/base', 'parent' => null, 'entries' => []]);

        (new FilesystemStorageBrowser($browser, $config))->listDirectory('');
    }

    public function testOpeningAFileStreamsItsContentWithNameSizeAndMime(): void
    {
        $text = "hello\nworld\n";
        $file = $this->tmp($text);
        $this->browser->expects(self::once())->method('resolveReadableFile')->with('/note.txt', '/real/configured', true)->willReturn($file);

        $resource = $this->storage->openResource('/note.txt');

        self::assertSame(basename($file), $resource['name']);
        self::assertSame('text/plain', $resource['mime']);
        self::assertSame(\strlen($text), $resource['size']);
        self::assertSame($text, stream_get_contents($resource['stream']));
        fclose($resource['stream']);
    }

    public function testUnknownContentFallsBackToAGenericMimeType(): void
    {
        $file = $this->tmp(random_bytes(64));
        $this->browser->method('resolveReadableFile')->willReturn($file);

        $resource = $this->storage->openResource('/blob');

        self::assertContains($resource['mime'], ['application/octet-stream', 'application/x-empty']);
        fclose($resource['stream']);
    }

    public function testAFileThatCannotBeOpenedIsReported(): void
    {
        $this->browser->method('resolveReadableFile')->willReturn('/no/such/dir/file.bin');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('The file is not readable.');

        $this->storage->openResource('/file.bin');
    }

    public function testPathValidationErrorsFromTheBrowserPropagate(): void
    {
        $this->browser->method('resolveReadableFile')->willThrowException(new \InvalidArgumentException('Path not found.'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path not found.');

        $this->storage->openResource('/nope');
    }
}
