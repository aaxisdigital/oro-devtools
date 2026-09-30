<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Filesystem;

use Aaxis\Bundle\DevToolsBundle\Exception\StorageException;
use Aaxis\Bundle\DevToolsBundle\Storage\SizeFormatter;

/**
 * Read-only filesystem browsing for the back-office "Filesystem Viewer" tool.
 *
 * Security model:
 *  - paths containing a ".." segment are always rejected;
 *  - every requested path is resolved with realpath();
 *  - when "restricted" is enabled, the resolved path must be inside the configured base path.
 */
class FilesystemBrowser
{
    /** Maximum number of bytes returned for a file preview. */
    public const int MAX_PREVIEW_BYTES = 524288;

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * Resolves the configured base path to its full (realpath) form; falls back to the
     * application start path (project dir) when no base path is configured.
     */
    public function resolveBasePath(string $configured): string
    {
        $path = trim($configured);
        if ($path === '') {
            $path = $this->projectDir;
        }
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }
        $projectReal = realpath($this->projectDir);

        return $projectReal !== false ? $projectReal : $this->projectDir;
    }

    /**
     * Lists a directory.
     *
     * @return array{
     *     path: string,
     *     parent: string|null,
     *     entries: array<int, array<string, mixed>>
     * }
     */
    public function listDirectory(string $requested, string $basePath, bool $restricted): array
    {
        $real = $this->resolveSafe($requested, $basePath, $restricted, true);

        return [
            'path' => $real,
            'parent' => $this->parentOf($real, $basePath, $restricted),
            'entries' => $this->readEntries($real),
        ];
    }

    /**
     * The ".." target to offer for a directory: none at the filesystem root, and none when going up
     * would leave the base path while restricted.
     */
    private function parentOf(string $real, string $basePath, bool $restricted): ?string
    {
        $up = \dirname($real);
        if ($up !== $real && (!$restricted || $this->isWithin($up, $basePath))) {
            return $up;
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>> directories first, then files, each group by name
     */
    private function readEntries(string $real): array
    {
        $entries = [];
        $handle = @opendir($real);
        if ($handle === false) {
            throw new StorageException('The directory is not readable.');
        }
        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $real . \DIRECTORY_SEPARATOR . $name;
            $entries[] = $this->describe($full, $name, is_dir($full) ? 'dir' : 'file');
        }
        closedir($handle);

        usort($entries, static fn (array $a, array $b): int => self::compareEntries($a, $b));

        return $entries;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function compareEntries(array $a, array $b): int
    {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'dir' ? -1 : 1;
        }

        return strcasecmp((string) $a['name'], (string) $b['name']);
    }

    /**
     * Resolves a readable file path (with all safety checks) for download/raw streaming.
     */
    public function resolveReadableFile(string $requested, string $basePath, bool $restricted): string
    {
        $real = $this->resolveSafe($requested, $basePath, $restricted, false);
        if (!is_file($real)) {
            throw new \InvalidArgumentException('The path is not a file.');
        }
        if (!is_readable($real)) {
            throw new StorageException('The file is not readable.');
        }

        return $real;
    }

    /**
     * Reads a bounded preview of a file.
     *
     * @return array{name: string, path: string, size: int, truncated: bool, binary: bool, content: string}
     */
    public function readFileContent(string $requested, string $basePath, bool $restricted): array
    {
        $real = $this->resolveSafe($requested, $basePath, $restricted, false);
        if (!is_file($real)) {
            throw new \InvalidArgumentException('The path is not a file.');
        }
        if (!is_readable($real)) {
            throw new StorageException('The file is not readable.');
        }

        $size = (int) filesize($real);
        $content = (string) file_get_contents($real, false, null, 0, self::MAX_PREVIEW_BYTES);
        $binary = $content !== '' && (str_contains($content, "\0") || !mb_check_encoding($content, 'UTF-8'));

        return [
            'name' => basename($real),
            'path' => $real,
            'size' => $size,
            'truncated' => $size > self::MAX_PREVIEW_BYTES,
            'binary' => $binary,
            'content' => $binary ? '' : $content,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(string $full, string $name, string $type): array
    {
        $size = $type === 'file' ? (int) @filesize($full) : 0;

        return [
            'name' => $name,
            'type' => $type,
            'path' => $full,
            'size' => $size,
            'sizeFormatted' => $type === 'file' ? SizeFormatter::format($size) : '',
            'created' => (int) @filectime($full),
            'modified' => (int) @filemtime($full),
            'ownerUser' => $this->ownerUser($full),
            'ownerGroup' => $this->ownerGroup($full),
            'readable' => is_readable($full),
        ];
    }

    private function resolveSafe(string $requested, string $basePath, bool $restricted, bool $expectDir): string
    {
        if ($this->hasDotDotSegment($requested)) {
            throw new \InvalidArgumentException('Relative ".." segments are not allowed.');
        }

        $requested = trim($requested);
        if ($requested === '') {
            $requested = $basePath;
        }

        $real = realpath($requested);
        if ($real === false) {
            throw new \InvalidArgumentException('Path not found.');
        }
        if ($expectDir && !is_dir($real)) {
            throw new \InvalidArgumentException('The path is not a directory.');
        }
        if ($restricted && !$this->isWithin($real, $basePath)) {
            throw new \InvalidArgumentException('Navigation outside the base path is not allowed.');
        }

        return $real;
    }

    private function hasDotDotSegment(string $path): bool
    {
        foreach (preg_split('#[/\\\\]+#', $path) ?: [] as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }

    private function isWithin(string $path, string $base): bool
    {
        return $path === $base || str_starts_with($path, rtrim($base, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR);
    }

    private function ownerUser(string $path): string
    {
        $uid = @fileowner($path);
        if ($uid === false) {
            return '';
        }
        if (\function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid($uid);
            if (\is_array($info) && isset($info['name'])) {
                return (string) $info['name'];
            }
        }

        return (string) $uid;
    }

    private function ownerGroup(string $path): string
    {
        $gid = @filegroup($path);
        if ($gid === false) {
            return '';
        }
        if (\function_exists('posix_getgrgid')) {
            $info = @posix_getgrgid($gid);
            if (\is_array($info) && isset($info['name'])) {
                return (string) $info['name'];
            }
        }

        return (string) $gid;
    }
}
