<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Storage;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface;

/**
 * Read-only browsing of an S3-compatible bucket (e.g. MinIO) for the Bucket Browser tool.
 *
 * Folders are modelled with the "/" delimiter (common prefixes); objects are files. The S3
 * client and bucket are built from configuration on each call so settings changes take effect
 * without a rebuild.
 *
 * The secret key is stored encrypted (the config field uses OroEncodedPlaceholderPasswordType) and
 * is decrypted here on read.
 */
class BucketStorageBrowser implements StorageBrowserInterface
{
    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly SymmetricCrypterInterface $crypter,
    ) {
    }

    public function getStartPath(): string
    {
        return '';
    }

    public function listDirectory(string $path): array
    {
        $prefix = $this->normalizePrefix($path);
        $result = $this->client()->listObjects($this->bucket(), $prefix);

        $entries = [];
        foreach ($result['prefixes'] as $commonPrefix) {
            $entries[] = $this->describeDir($commonPrefix);
        }
        foreach ($result['objects'] as $object) {
            $entries[] = $this->describeFile($object['key'], $object['size'], $object['lastModified']);
        }

        usort($entries, static function (array $a, array $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return ['path' => $prefix, 'parent' => $this->parentPrefix($prefix), 'entries' => $entries];
    }

    public function readFileContent(string $path): array
    {
        $key = ltrim($path, '/');
        if ($key === '' || str_ends_with($key, '/')) {
            throw new \InvalidArgumentException('The path is not an object.');
        }

        $object = $this->client()->getObject($this->bucket(), $key, self::MAX_PREVIEW_BYTES);
        $content = $object['body'];
        $size = $object['contentLength'] ?? \strlen($content);
        $binary = $content !== '' && (str_contains($content, "\0") || !mb_check_encoding($content, 'UTF-8'));

        return [
            'name' => $this->basename($key),
            'path' => $key,
            'size' => $size,
            'truncated' => $size > self::MAX_PREVIEW_BYTES,
            'binary' => $binary,
            'content' => $binary ? '' : $content,
        ];
    }

    public function openResource(string $path): array
    {
        $key = ltrim($path, '/');
        if ($key === '' || str_ends_with($key, '/')) {
            throw new \InvalidArgumentException('The path is not an object.');
        }

        $object = $this->client()->getObject($this->bucket(), $key);
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new \RuntimeException('Unable to buffer the object.');
        }
        fwrite($stream, $object['body']);
        rewind($stream);

        return [
            'name' => $this->basename($key),
            'mime' => $object['contentType'],
            'size' => $object['contentLength'],
            'stream' => $stream,
        ];
    }

    /**
     * @param array<string, string> $overrides values entered in the config form (unsaved)
     *
     * @return array{success: bool, message: string, details: array<string, string>}
     */
    public function testConnection(array $overrides = []): array
    {
        $url = $this->setting('url', $overrides);
        $bucket = $this->setting('name', $overrides);
        if ($url === '' || $bucket === '') {
            return [
                'success' => false,
                'message' => 'Configure the endpoint URL and bucket name first.',
                'details' => [],
            ];
        }

        $client = new S3Client($url, $this->setting('user', $overrides), $this->settingSecret($overrides));

        return $client->testConnection($bucket);
    }

    public function isReadOnly(): bool
    {
        return (bool) $this->configManager->get('aaxis_devtools.bucket_browser_read_only');
    }

    public function createFolder(string $path, string $name): void
    {
        $this->assertWritable();
        $folder = trim(str_replace(['/', '\\'], '', $name));
        if ($folder === '') {
            throw new \InvalidArgumentException('A folder name is required.');
        }
        $key = $this->normalizePrefix($path) . $folder . '/';
        $this->client()->putObject($this->bucket(), $key, '', 'application/x-directory');
    }

    public function uploadFile(string $path, string $tmpFile, string $filename, string $contentType): void
    {
        $this->assertWritable();
        $name = basename(str_replace('\\', '/', $filename));
        if ($name === '') {
            throw new \InvalidArgumentException('A file name is required.');
        }
        $body = (string) file_get_contents($tmpFile);
        $key = $this->normalizePrefix($path) . $name;
        $this->client()->putObject($this->bucket(), $key, $body, $contentType);
    }

    public function delete(string $path, bool $isDir): void
    {
        $this->assertWritable();
        $client = $this->client();
        $bucket = $this->bucket();

        if ($isDir) {
            $prefix = $this->normalizePrefix($path);
            $keys = $client->listAllKeys($bucket, $prefix);
            // Ensure the folder marker object itself is removed too.
            if (!\in_array($prefix, $keys, true)) {
                $keys[] = $prefix;
            }
            foreach ($keys as $key) {
                $client->deleteObject($bucket, $key);
            }
        } else {
            $client->deleteObject($bucket, ltrim($path, '/'));
        }
    }

    private function assertWritable(): void
    {
        if ($this->isReadOnly()) {
            throw new \RuntimeException('The bucket is configured as read-only.');
        }
    }

    private function client(): S3Client
    {
        $url = $this->config('url');
        if ($url === '') {
            throw new \RuntimeException('The bucket endpoint URL is not configured.');
        }

        return new S3Client($url, $this->config('user'), $this->savedSecret());
    }

    private function bucket(): string
    {
        return $this->config('name');
    }

    private function config(string $key): string
    {
        return trim((string) $this->configManager->get('aaxis_devtools.bucket_browser_' . $key));
    }

    /**
     * The saved secret key, decrypted. Stored encrypted by OroEncodedPlaceholderPasswordType; an
     * unset or undecryptable value yields an empty string.
     */
    private function savedSecret(): string
    {
        $encrypted = trim((string) $this->configManager->get('aaxis_devtools.bucket_browser_pass'));
        if ($encrypted === '') {
            return '';
        }

        try {
            return (string) $this->crypter->decryptData($encrypted);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Returns an override value when the user actually entered one (test in edit mode); an empty
     * override falls back to the saved config (e.g. the password field renders blank on load).
     *
     * @param array<string, string> $overrides
     */
    private function setting(string $key, array $overrides): string
    {
        if (\array_key_exists($key, $overrides) && trim((string) $overrides[$key]) !== '') {
            return trim((string) $overrides[$key]);
        }

        return $this->config($key);
    }

    /**
     * The secret to use for a connection test: the value the user just typed in the config form
     * (plaintext) when present, otherwise the saved (decrypted) secret. The masked placeholder
     * rendered for an already-stored secret (a run of "*") is treated as "not entered".
     *
     * @param array<string, string> $overrides
     */
    private function settingSecret(array $overrides): string
    {
        $entered = \array_key_exists('pass', $overrides) ? trim((string) $overrides['pass']) : '';
        if ($entered !== '' && trim($entered, '*') !== '') {
            return $entered;
        }

        return $this->savedSecret();
    }

    private function normalizePrefix(string $path): string
    {
        $prefix = ltrim(trim($path), '/');

        return $prefix === '' || str_ends_with($prefix, '/') ? $prefix : $prefix . '/';
    }

    private function parentPrefix(string $prefix): ?string
    {
        if ($prefix === '') {
            return null;
        }
        $trimmed = rtrim($prefix, '/');
        $pos = strrpos($trimmed, '/');

        return $pos === false ? '' : substr($trimmed, 0, $pos + 1);
    }

    private function basename(string $key): string
    {
        $trimmed = rtrim($key, '/');
        $pos = strrpos($trimmed, '/');

        return $pos === false ? $trimmed : substr($trimmed, $pos + 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function describeDir(string $commonPrefix): array
    {
        return [
            'name' => $this->basename($commonPrefix),
            'type' => 'dir',
            'path' => $commonPrefix,
            'size' => 0,
            'sizeFormatted' => '',
            'created' => 0,
            'modified' => 0,
            'ownerUser' => '',
            'ownerGroup' => '',
            'readable' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeFile(string $key, int $size, int $lastModified): array
    {
        return [
            'name' => $this->basename($key),
            'type' => 'file',
            'path' => $key,
            'size' => $size,
            'sizeFormatted' => $this->formatSize($size),
            'created' => 0,
            'modified' => $lastModified,
            'ownerUser' => '',
            'ownerGroup' => '',
            'readable' => true,
        ];
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $i = 0;
        while ($value >= 1024 && $i < \count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) $bytes : number_format($value, 1)) . ' ' . $units[$i];
    }
}
