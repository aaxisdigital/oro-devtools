<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Storage;

use Aaxis\Bundle\DevToolsBundle\Async\Topic\BucketFolderArchiveTopic;
use Aaxis\Bundle\DevToolsBundle\Exception\StorageException;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;

/**
 * "Download folder" for the Bucket Viewer: a ZIP of every file below a bucket folder (recursive).
 *
 * 1. Scan: the folder is listed one page (up to 1000 objects) per call, so the UI can show a live
 *    file count and total size, and no single request has to walk a huge folder. Each key and size
 *    is saved as it is counted ({@see FolderScanStore}, shared by web nodes and message consumers).
 *    The archive later holds exactly that snapshot, so files added afterwards (the archive itself,
 *    for one) are never picked up. Folder-marker objects ("…/" keys) are not files and are skipped.
 * 2a. Small folder (below BOTH configured limits, see {@see getDirectDownloadLimits()}): the ZIP is
 *     built during the request and downloaded straight away.
 * 2b. Anything larger is archived by a message consumer, which uploads the ZIP into the scanned
 *     folder under a name the user chose. This needs write access. If it fails, a
 *     "<name>.error.txt" note with the reason is written there instead, so whoever checks the folder
 *     later finds an answer either way.
 *
 * A scan belongs to the user who started it and expires two days after its last update.
 */
class BucketFolderArchiver
{
    public const string MODE_EMPTY = 'empty';
    public const string MODE_DIRECT = 'direct';
    public const string MODE_BACKGROUND = 'background';

    public const int DEFAULT_DIRECT_MAX_FILES = 1000;
    public const int DEFAULT_DIRECT_MAX_SIZE_MB = 5;

    public function __construct(
        private readonly BucketStorageBrowser $storage,
        private readonly FolderScanStore $scans,
        private readonly FolderZipWriter $zipWriter,
        private readonly MessageProducerInterface $producer,
        private readonly ConfigManager $configManager,
    ) {
    }

    /**
     * Starts scanning the folder $path for $owner and reads its first page.
     *
     * @return array<string, mixed> see {@see describe()}
     */
    public function startScan(string $path, string $owner): array
    {
        $this->scans->purgeExpired();

        $prefix = ObjectKey::normalizePrefix($path);
        $state = [
            'id' => FolderScanStore::newId(),
            'owner' => $owner,
            'prefix' => $prefix,
            'root' => self::folderName($prefix !== '' ? ObjectKey::basename($prefix) : $this->storage->bucket()),
            'files' => 0,
            'bytes' => 0,
            'pages' => 0,
            'nextToken' => '',
            'complete' => false,
            'target' => null,
        ];

        return $this->scanNextPage($state);
    }

    /**
     * Reads the next page of a scan (a completed scan is just described again).
     *
     * @return array<string, mixed> see {@see describe()}
     */
    public function continueScan(string $id, string $owner): array
    {
        $state = $this->ownedScan($id, $owner);

        return $state['complete'] ? $this->describe($state) : $this->scanNextPage($state);
    }

    /**
     * Builds the ZIP of a small, completed scan into a local temporary file. The caller sends it and
     * deletes it afterwards.
     *
     * @return array{file: string, name: string}
     */
    public function buildDirectArchive(string $id, string $owner): array
    {
        $state = $this->completedScan($id, $owner);
        $limits = $this->getDirectDownloadLimits();
        $mode = self::mode($state, $limits);
        if ($mode === self::MODE_EMPTY) {
            throw new \InvalidArgumentException('The folder has no files to download.');
        }
        if ($mode !== self::MODE_DIRECT) {
            throw new \InvalidArgumentException(sprintf(
                'The folder is too large for a direct download (limit: under %d files and under %s).',
                $limits['files'],
                SizeFormatter::format($limits['bytes'])
            ));
        }

        $zipFile = self::tempFile();
        try {
            $this->writeZip($state, $zipFile);
        } catch (\Throwable $e) {
            @unlink($zipFile);
            throw $e;
        }

        return ['file' => $zipFile, 'name' => $state['root'] . '.zip'];
    }

    /**
     * Queues the background archive of a completed scan, to be written into the scanned folder as
     * $filename (".zip" is appended when missing).
     *
     * @return string the object key the archive will be written to
     */
    public function requestArchive(string $id, string $owner, string $filename): string
    {
        $state = $this->completedScan($id, $owner);
        if ($state['files'] === 0) {
            throw new \InvalidArgumentException('The folder has no files to archive.');
        }
        if ($state['target'] !== null) {
            throw new \InvalidArgumentException('An archive of this folder scan has already been requested.');
        }
        $this->assertWritable();

        $name = self::archiveFilename($filename);
        $key = $state['prefix'] . $name;
        if ($this->storage->client()->objectExists($this->storage->bucket(), $key)) {
            throw new \InvalidArgumentException(sprintf('"%s" already exists in this folder. Choose another name.', $name));
        }

        $state['target'] = $key;
        $this->scans->save($state);
        $this->producer->send(BucketFolderArchiveTopic::getName(), ['scanId' => $state['id'], 'targetKey' => $key]);

        return $key;
    }

    /**
     * Message-consumer side of {@see requestArchive()}: zips the scanned snapshot and uploads it. The
     * scan is discarded afterwards, whatever the outcome.
     *
     * @return int the number of files archived
     */
    public function generateArchive(string $scanId, string $targetKey): int
    {
        $state = $this->scans->get($scanId);
        if ($state === null || !$state['complete'] || $state['target'] !== $targetKey) {
            throw new StorageException('The folder scan has expired. Start the folder download again.');
        }

        $zipFile = self::tempFile();
        try {
            $added = $this->writeZip($state, $zipFile);
            $this->upload($targetKey, $zipFile, 'application/zip');

            return $added;
        } finally {
            @unlink($zipFile);
            $this->scans->delete($scanId);
        }
    }

    /**
     * Leaves "<target>.error.txt" where a background archive should have appeared.
     */
    public function reportFailure(string $targetKey, string $reason): void
    {
        $note = self::tempFile();
        try {
            file_put_contents($note, sprintf(
                "The archive \"%s\" could not be generated (%s).\n\nReason: %s\n",
                ObjectKey::basename($targetKey),
                date(\DATE_ATOM),
                $reason
            ));
            $this->upload($targetKey . '.error.txt', $note, 'text/plain');
        } finally {
            @unlink($note);
        }
    }

    /**
     * A folder zips in the browser request only below BOTH limits (fewer files than "files" and fewer
     * bytes than "bytes"); larger ones are archived in the background. Read from the Bucket Viewer
     * settings; a missing or non-positive value falls back to the default (1000 files, 5 MB).
     *
     * @return array{files: int, bytes: int}
     */
    public function getDirectDownloadLimits(): array
    {
        $files = (int) $this->configManager->get('aaxis_devtools.bucket_viewer_direct_max_files');
        $megabytes = (int) $this->configManager->get('aaxis_devtools.bucket_viewer_direct_max_size_mb');

        return [
            'files' => $files > 0 ? $files : self::DEFAULT_DIRECT_MAX_FILES,
            'bytes' => ($megabytes > 0 ? $megabytes : self::DEFAULT_DIRECT_MAX_SIZE_MB) * 1024 * 1024,
        ];
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function scanNextPage(array $state): array
    {
        $page = $this->storage->client()->listObjectsPage($this->storage->bucket(), $state['prefix'], $state['nextToken']);

        $entries = [];
        foreach ($page['objects'] as $object) {
            if (!str_ends_with($object['key'], '/')) {
                $entries[] = [$object['key'], $object['size']];
                $state['bytes'] += $object['size'];
            }
        }
        if ($entries !== []) {
            $state['pages']++;
            $state['files'] += \count($entries);
            $this->scans->savePage($state['id'], $state['pages'], $entries);
        }
        $state['nextToken'] = $page['nextToken'];
        $state['complete'] = $page['nextToken'] === '';
        $this->scans->save($state);

        return $this->describe($state);
    }

    /**
     * What the UI needs to render the scan's progress and outcome.
     *
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function describe(array $state): array
    {
        $limits = $this->getDirectDownloadLimits();

        return [
            'id' => $state['id'],
            'path' => $state['prefix'],
            'files' => $state['files'],
            'bytes' => $state['bytes'],
            'bytesFormatted' => SizeFormatter::format($state['bytes']),
            'complete' => $state['complete'],
            'mode' => $state['complete'] ? self::mode($state, $limits) : null,
            'archiveName' => $state['root'] . '.zip',
            'suggestedFilename' => $state['root'] . '-' . date('Ymd-His') . '.zip',
            'limits' => $limits + ['bytesFormatted' => SizeFormatter::format($limits['bytes'])],
        ];
    }

    /**
     * @param array<string, mixed>          $state
     * @param array{files: int, bytes: int} $limits
     */
    private static function mode(array $state, array $limits): string
    {
        if ($state['files'] === 0) {
            return self::MODE_EMPTY;
        }

        return $state['files'] < $limits['files'] && $state['bytes'] < $limits['bytes']
            ? self::MODE_DIRECT
            : self::MODE_BACKGROUND;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeZip(array $state, string $zipFile): int
    {
        $client = $this->storage->client();
        $bucket = $this->storage->bucket();

        return $this->zipWriter->write(
            $zipFile,
            $state['root'],
            $state['prefix'],
            $this->scans->keys($state['id'], $state['pages']),
            static fn (string $key, string $localFile): bool => $client->downloadObject($bucket, $key, $localFile)
        );
    }

    private function upload(string $key, string $localFile, string $contentType): void
    {
        $this->assertWritable();
        $this->storage->client()->putObjectFromFile($this->storage->bucket(), $key, $localFile, $contentType);
    }

    private function assertWritable(): void
    {
        if ($this->storage->isReadOnly()) {
            throw new StorageException('The bucket is configured as read-only.');
        }
    }

    /**
     * A name safe as the archive's root directory and download file name.
     */
    private static function folderName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f\/\\\\]+/', '_', $name));

        return trim($name, '.') === '' ? 'bucket' : $name;
    }

    /**
     * The user's archive name reduced to a plain file name ending in ".zip".
     */
    private static function archiveFilename(string $filename): string
    {
        $name = ObjectKey::basename(str_replace('\\', '/', $filename));
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', '', $name));
        if (trim($name, '.') === '') {
            throw new \InvalidArgumentException('Enter a file name for the archive.');
        }

        return str_ends_with(strtolower($name), '.zip') ? $name : $name . '.zip';
    }

    /**
     * @return array<string, mixed>
     */
    private function ownedScan(string $id, string $owner): array
    {
        $state = $this->scans->get($id);
        if ($state === null || $state['owner'] !== $owner) {
            throw new \InvalidArgumentException('The folder scan was not found or has expired. Start the folder download again.');
        }

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function completedScan(string $id, string $owner): array
    {
        $state = $this->ownedScan($id, $owner);
        if (!$state['complete']) {
            throw new \InvalidArgumentException('The folder scan has not finished yet.');
        }

        return $state;
    }

    private static function tempFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'aaxis_zip_');
        if ($file === false) {
            throw new StorageException('Unable to create a temporary file.');
        }

        return $file;
    }
}
