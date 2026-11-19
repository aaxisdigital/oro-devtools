<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Storage;

use Aaxis\Bundle\DevToolsBundle\Exception\StorageException;
use Oro\Bundle\GaufretteBundle\FileManager;

/**
 * Persists Bucket Viewer folder scans (see {@see BucketFolderArchiver}) in the bundle's private
 * Gaufrette storage, which web nodes and message consumers share. A scan is one state file plus one
 * file per listing page holding the [key, size] pairs counted on that page; all files of a scan share
 * the "bucket-archive-<id>." prefix. Flat names (no sub-directories) keep it portable across adapters.
 */
class FolderScanStore
{
    private const int TTL = 172800;
    private const string FILE_PREFIX = 'bucket-archive-';
    private const string STATE_SUFFIX = '.state.json';

    public function __construct(private readonly FileManager $fileManager)
    {
    }

    /**
     * @return array<string, mixed>|null null when the scan does not exist (or the id is malformed)
     */
    public function get(string $id): ?array
    {
        if (!self::isValidId($id)) {
            return null;
        }
        $content = $this->fileManager->getFileContent(self::stateFile($id), false);
        $state = $content !== null ? json_decode($content, true) : null;

        return \is_array($state) && ($state['id'] ?? null) === $id ? $state : null;
    }

    /**
     * @param array<string, mixed> $state must hold a valid "id"
     */
    public function save(array $state): void
    {
        $state['updatedAt'] = time();
        $this->fileManager->writeToStorage(self::encode($state), self::stateFile((string) $state['id']));
    }

    /**
     * @param array<int, array{0: string, 1: int}> $entries
     */
    public function savePage(string $id, int $page, array $entries): void
    {
        $this->fileManager->writeToStorage(self::encode($entries), self::pageFile($id, $page));
    }

    /**
     * Every key recorded for a scan, page by page.
     *
     * @return \Generator<int, string>
     */
    public function keys(string $id, int $pages): \Generator
    {
        for ($page = 1; $page <= $pages; $page++) {
            $content = $this->fileManager->getFileContent(self::pageFile($id, $page), false);
            $entries = $content !== null ? json_decode($content, true) : null;
            if (!\is_array($entries)) {
                throw new StorageException('The folder scan is incomplete. Start the folder download again.');
            }
            foreach ($entries as $entry) {
                yield (string) $entry[0];
            }
        }
    }

    public function delete(string $id): void
    {
        if (self::isValidId($id)) {
            $this->fileManager->deleteAllFiles(self::FILE_PREFIX . $id . '.');
        }
    }

    /**
     * Drops scans nobody finished with (closed dialog, abandoned download) once they expire.
     */
    public function purgeExpired(): void
    {
        foreach ($this->fileManager->findFiles(self::FILE_PREFIX) as $file) {
            if (!str_ends_with($file, self::STATE_SUFFIX)) {
                continue;
            }
            $id = substr($file, \strlen(self::FILE_PREFIX), -\strlen(self::STATE_SUFFIX));
            $state = $this->get($id);
            if ($state === null || time() - (int) ($state['updatedAt'] ?? 0) > self::TTL) {
                $this->delete($id);
            }
        }
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function isValidId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $id) === 1;
    }

    private static function stateFile(string $id): string
    {
        return self::FILE_PREFIX . $id . self::STATE_SUFFIX;
    }

    private static function pageFile(string $id, int $page): string
    {
        return sprintf('%s%s.page-%06d.json', self::FILE_PREFIX, $id, $page);
    }

    private static function encode(mixed $data): string
    {
        return (string) json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
