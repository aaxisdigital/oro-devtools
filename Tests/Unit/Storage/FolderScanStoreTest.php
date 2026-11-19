<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Storage;

use Aaxis\Bundle\DevToolsBundle\Exception\StorageException;
use Aaxis\Bundle\DevToolsBundle\Storage\FolderScanStore;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\InMemoryFileManager;
use PHPUnit\Framework\TestCase;

class FolderScanStoreTest extends TestCase
{
    private InMemoryFileManager $files;
    private FolderScanStore $store;

    protected function setUp(): void
    {
        $this->files = new InMemoryFileManager();
        $this->store = new FolderScanStore($this->files);
    }

    public function testNewIdsAre32HexCharacters(): void
    {
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', FolderScanStore::newId());
        self::assertNotSame(FolderScanStore::newId(), FolderScanStore::newId());
    }

    public function testSavesAndReadsAScanStampingItsUpdateTime(): void
    {
        $id = FolderScanStore::newId();
        $this->store->save(['id' => $id, 'prefix' => 'a/ç/', 'files' => 2]);

        $state = $this->store->get($id);

        self::assertSame('a/ç/', $state['prefix']);
        self::assertEqualsWithDelta(time(), $state['updatedAt'], 2);
        self::assertSame(['bucket-archive-' . $id . '.state.json'], array_keys($this->files->files));
        self::assertStringContainsString('"a/ç/"', $this->files->files['bucket-archive-' . $id . '.state.json']);
    }

    /**
     * @dataProvider unknownIdProvider
     */
    public function testAnUnknownOrMalformedIdHasNoScan(string $id): void
    {
        $this->files->files['bucket-archive-../x.state.json'] = '{"id":"../x"}';

        self::assertNull($this->store->get($id));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownIdProvider(): iterable
    {
        yield 'unknown' => [str_repeat('a', 32)];
        yield 'path traversal' => ['../x'];
        yield 'upper case' => [str_repeat('A', 32)];
        yield 'empty' => [''];
    }

    public function testAStateFileForAnotherIdOrNotJsonIsIgnored(): void
    {
        $id = str_repeat('b', 32);
        $this->files->files['bucket-archive-' . $id . '.state.json'] = '{"id":"' . str_repeat('c', 32) . '"}';
        self::assertNull($this->store->get($id));

        $this->files->files['bucket-archive-' . $id . '.state.json'] = 'not json';
        self::assertNull($this->store->get($id));
    }

    public function testYieldsTheKeysOfEveryPageInOrder(): void
    {
        $id = FolderScanStore::newId();
        $this->store->savePage($id, 1, [['a/1', 1], ['a/2', 2]]);
        $this->store->savePage($id, 2, [['a/sub/3', 3]]);

        self::assertSame(['a/1', 'a/2', 'a/sub/3'], iterator_to_array($this->store->keys($id, 2), false));
        self::assertArrayHasKey('bucket-archive-' . $id . '.page-000002.json', $this->files->files);
    }

    public function testAMissingPageMeansTheScanIsIncomplete(): void
    {
        $id = FolderScanStore::newId();
        $this->store->savePage($id, 1, [['a/1', 1]]);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('The folder scan is incomplete. Start the folder download again.');

        iterator_to_array($this->store->keys($id, 2), false);
    }

    public function testDeletesEveryFileOfOneScanOnly(): void
    {
        $id = FolderScanStore::newId();
        $other = FolderScanStore::newId();
        $this->store->save(['id' => $id]);
        $this->store->savePage($id, 1, [['k', 1]]);
        $this->store->save(['id' => $other]);

        $this->store->delete($id);
        $this->store->delete('../not-an-id');

        self::assertSame(['bucket-archive-' . $other . '.state.json'], array_keys($this->files->files));
    }

    public function testPurgesExpiredAndUnreadableScansButKeepsRecentOnes(): void
    {
        $fresh = FolderScanStore::newId();
        $expired = FolderScanStore::newId();
        $broken = FolderScanStore::newId();
        $this->store->save(['id' => $fresh]);
        $this->store->savePage($fresh, 1, [['k', 1]]);
        $this->files->files['bucket-archive-' . $expired . '.state.json'] = json_encode(['id' => $expired, 'updatedAt' => time() - 172801]);
        $this->files->files['bucket-archive-' . $expired . '.page-000001.json'] = '[["k",1]]';
        $this->files->files['bucket-archive-' . $broken . '.state.json'] = 'garbage';
        $this->files->files['unrelated.txt'] = 'x';

        $this->store->purgeExpired();

        self::assertSame([
            'bucket-archive-' . $fresh . '.state.json',
            'bucket-archive-' . $fresh . '.page-000001.json',
            'unrelated.txt',
        ], array_keys($this->files->files));
    }
}
