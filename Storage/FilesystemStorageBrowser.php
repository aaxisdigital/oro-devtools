<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Storage;

use Aaxis\Bundle\DevToolsBundle\Filesystem\FilesystemBrowser;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Symfony\Component\Mime\MimeTypes;

/**
 * Adapts the local {@see FilesystemBrowser} (which holds the path-safety logic) to the shared
 * {@see StorageBrowserInterface}, reading its base-path/restricted settings from configuration.
 */
class FilesystemStorageBrowser implements StorageBrowserInterface
{
    public function __construct(
        private readonly FilesystemBrowser $browser,
        private readonly ConfigManager $configManager,
    ) {
    }

    public function getStartPath(): string
    {
        return $this->basePath();
    }

    public function listDirectory(string $path): array
    {
        [$basePath, $restricted] = $this->settings();

        return $this->browser->listDirectory($path, $basePath, $restricted);
    }

    public function readFileContent(string $path): array
    {
        [$basePath, $restricted] = $this->settings();

        return $this->browser->readFileContent($path, $basePath, $restricted);
    }

    public function openResource(string $path): array
    {
        [$basePath, $restricted] = $this->settings();
        $file = $this->browser->resolveReadableFile($path, $basePath, $restricted);

        $stream = @fopen($file, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('The file is not readable.');
        }

        return [
            'name' => basename($file),
            'mime' => MimeTypes::getDefault()->guessMimeType($file) ?: 'application/octet-stream',
            'size' => (int) filesize($file),
            'stream' => $stream,
        ];
    }

    private function basePath(): string
    {
        return $this->browser->resolveBasePath((string) $this->configManager->get('aaxis_devtools.filesystem_browser_base_path'));
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function settings(): array
    {
        return [
            $this->basePath(),
            (bool) $this->configManager->get('aaxis_devtools.filesystem_browser_restricted'),
        ];
    }
}
