<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Storage;

use Aaxis\Bundle\DevToolsBundle\Exception\StorageException;

/**
 * Writes a ZIP of object-store files, laid out as "<root>/<path below the folder>".
 *
 * Each object is streamed to a local temporary file first (ZipArchive reads the files only when it
 * closes the archive and opens them one at a time), so memory use does not depend on object sizes.
 * Disk use peaks at about twice the archive's content while the ZIP is written.
 */
class FolderZipWriter
{
    /** Formats that are already compressed: stored as-is instead of being deflated a second time. */
    private const array STORED_EXTENSIONS = [
        'zip', 'gz', 'tgz', 'bz2', 'xz', 'zst', '7z', 'rar', 'jar', 'war',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic',
        'mp3', 'mp4', 'm4a', 'mov', 'avi', 'mkv', 'webm', 'ogg',
        'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'pdf',
    ];

    /**
     * @param iterable<string>                      $keys     object keys, all below $prefix
     * @param \Closure(string $key, string $localFile): bool $download streams an object into a local
     *                                                                 file; false when it no longer exists
     *
     * @return int the number of files written (objects that no longer exist are skipped)
     */
    public function write(string $zipFile, string $root, string $prefix, iterable $keys, \Closure $download): int
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new StorageException('Unable to create the archive file.');
        }

        $workDir = self::tempDir();
        $added = 0;
        $closing = false;
        try {
            $n = 0;
            foreach ($keys as $key) {
                $entry = self::entryName($root, $prefix, $key);
                $local = $workDir . '/' . ++$n;
                if ($entry === null || !$download($key, $local)) {
                    continue;
                }
                $zip->addFile($local, $entry);
                if (\in_array(strtolower(pathinfo($entry, \PATHINFO_EXTENSION)), self::STORED_EXTENSIONS, true)) {
                    $zip->setCompressionName($entry, \ZipArchive::CM_STORE);
                }
                $added++;
            }
            if ($added === 0) {
                throw new StorageException('None of the scanned files exist any more.');
            }
            $closing = true;
            // A write failure also raises a PHP warning; the exception below carries the same reason.
            if (!@$zip->close()) {
                throw new StorageException('Unable to write the archive: ' . $zip->getStatusString());
            }
        } catch (\Throwable $e) {
            if (!$closing) {
                // Drop the pending entries so closing does not try to read the temporary files.
                $zip->unchangeAll();
                $zip->close();
            }
            throw $e;
        } finally {
            self::removeDir($workDir);
        }

        return $added;
    }

    /**
     * The ZIP entry for $key: "<root>/<path below $prefix>". Empty, "." and ".." segments are dropped
     * so the archive cannot write outside its folder when extracted; null when nothing is left.
     */
    public static function entryName(string $root, string $prefix, string $key): ?string
    {
        $relative = str_starts_with($key, $prefix) ? substr($key, \strlen($prefix)) : $key;
        $segments = array_filter(
            explode('/', str_replace('\\', '/', $relative)),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..'
        );

        return $segments === [] ? null : $root . '/' . implode('/', $segments);
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/aaxis_zip_' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700) && !is_dir($dir)) {
            throw new StorageException('Unable to create a temporary directory.');
        }

        return $dir;
    }

    private static function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
