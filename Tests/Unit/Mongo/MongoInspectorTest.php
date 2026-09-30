<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Mongo;

use Aaxis\Bundle\DevToolsBundle\Mongo\MongoInspector;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\FakeMongoCursor;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Model\BSONDocument;
use MongoDB\Model\CollectionInfo;
use MongoDB\Model\DatabaseInfo;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MongoInspectorTest extends TestCase
{
    private ConfigManager&MockObject $config;
    private SymmetricCrypterInterface&MockObject $crypter;
    /** @var array<string, mixed> */
    private array $settings = [];
    /** @var array<int, array{dsn: string, options: array<string, mixed>}> */
    private array $created = [];
    /** @var array<string, Client&MockObject> client per DSN */
    private array $clients = [];
    /** @var array<string, callable> command name => handler(Client): array */
    private array $commands = [];
    /** @var string[] DSNs whose client creation fails */
    private array $unreachable = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigManager::class);
        $this->config->method('get')->willReturnCallback(fn (string $key) => $this->settings[$key] ?? null);
        $this->crypter = $this->createMock(SymmetricCrypterInterface::class);
        $this->crypter->method('decryptData')->willReturnCallback(static fn (string $data) => ' dec(' . $data . ') ');
    }

    private function inspector(string $defaultDsn = 'mongodb://priv:27017'): MongoInspector
    {
        return new class ($defaultDsn, $this->config, $this->crypter, $this) extends MongoInspector {
            public function __construct(string $dsn, ConfigManager $config, SymmetricCrypterInterface $crypter, private readonly MongoInspectorTest $test)
            {
                parent::__construct($dsn, $config, $crypter);
            }

            protected function createClient(string $dsn, array $uriOptions): Client
            {
                return $this->test->clientFor($dsn, $uriOptions);
            }

            protected function adminCommand(Client $client, array $command): array
            {
                return $this->test->runCommand($client, $command);
            }
        };
    }

    /**
     * @param array<string, mixed> $uriOptions
     *
     * @internal used by the inspector under test
     */
    public function clientFor(string $dsn, array $uriOptions): Client
    {
        $this->created[] = ['dsn' => $dsn, 'options' => $uriOptions];
        if (\in_array($dsn, $this->unreachable, true)) {
            throw new \RuntimeException('cannot reach ' . $dsn);
        }

        return $this->clients[$dsn] ??= $this->createMock(Client::class);
    }

    /**
     * @param array<string, mixed> $command
     *
     * @return array<int, object>
     *
     * @internal used by the inspector under test
     */
    public function runCommand(Client $client, array $command): array
    {
        $name = (string) array_key_first($command);
        if (!isset($this->commands[$name])) {
            throw new \RuntimeException('command not allowed: ' . $name);
        }

        return ($this->commands[$name])($client);
    }

    /**
     * @param string[] $names
     */
    private function databases(array $names, int $size = 1000): \ArrayIterator
    {
        return new \ArrayIterator(array_map(
            static fn (string $name) => new DatabaseInfo(['name' => $name, 'sizeOnDisk' => $size, 'empty' => false]),
            $names
        ));
    }

    /**
     * @param string[] $names
     */
    private function collectionInfos(array $names): \ArrayIterator
    {
        return new \ArrayIterator(array_map(static fn (string $name) => new CollectionInfo(['name' => $name, 'type' => 'collection']), $names));
    }

    // --- overview ------------------------------------------------------------

    public function testOverviewOfThePrivateConnectionUsingTheApplicationDefault(): void
    {
        $client = $this->clientFor('mongodb://priv:27017', []);
        $this->created = [];
        $this->commands['buildInfo'] = static fn () => [(object) ['version' => '7.0.5']];
        $client->method('listDatabases')->willReturn($this->databases(['admin', 'shop'], 4096));
        $database = $this->createMock(Database::class);
        $database->method('listCollections')->willReturn($this->collectionInfos(['a', 'b', 'c']));
        $client->method('selectDatabase')->willReturn($database);

        $overview = $this->inspector()->getOverview();

        self::assertSame([
            [
                'connection' => 'private',
                'server' => ['Version' => '7.0.5'],
                'databases' => [
                    ['db' => 'admin', 'collections' => 3, 'sizeOnDisk' => 4096, 'connection' => 'private'],
                    ['db' => 'shop', 'collections' => 3, 'sizeOnDisk' => 4096, 'connection' => 'private'],
                ],
            ],
        ], $overview['connections']);
        self::assertSame([['dsn' => 'mongodb://priv:27017', 'options' => []]], $this->created);
    }

    public function testBothConnectionsAreListedInDisplayOrder(): void
    {
        $this->settings['aaxis_devtools.mongodb_viewer_private_dsn'] = 'mongodb://p:1';
        $this->settings['aaxis_devtools.mongodb_viewer_public_dsn'] = 'mongodb://q:2';
        $this->commands['buildInfo'] = static fn () => [];
        foreach (['mongodb://p:1', 'mongodb://q:2'] as $dsn) {
            $this->clientFor($dsn, [])->method('listDatabases')->willReturn($this->databases([]));
        }

        $connections = $this->inspector()->getOverview()['connections'];

        self::assertSame(['private', 'public'], array_column($connections, 'connection'));
        self::assertSame([], $connections[0]['server']);
    }

    public function testOnlyTheConfiguredPublicConnectionIsListedWhenPrivateHasNoDsn(): void
    {
        $this->settings['aaxis_devtools.mongodb_viewer_public_dsn'] = 'mongodb://q:2';
        $this->commands['buildInfo'] = static fn () => [(object) ['version' => '6.0']];
        $this->clientFor('mongodb://q:2', [])->method('listDatabases')->willReturn($this->databases([]));

        $connections = $this->inspector('')->getOverview()['connections'];

        self::assertSame(['public'], array_column($connections, 'connection'));
    }

    public function testNothingIsListedWithoutAnyDsn(): void
    {
        self::assertSame(['connections' => []], $this->inspector('')->getOverview());
    }

    public function testOneEndpointBeingDownDoesNotHideTheOtherAndItsCredentialsAreRedacted(): void
    {
        $this->settings['aaxis_devtools.mongodb_viewer_private_dsn'] = 'mongodb://user:pw@down:1';
        $this->settings['aaxis_devtools.mongodb_viewer_public_dsn'] = 'mongodb://q:2';
        $this->unreachable = ['mongodb://user:pw@down:1'];
        $this->commands['buildInfo'] = static fn () => [];
        $this->clientFor('mongodb://q:2', [])->method('listDatabases')->willReturn($this->databases(['x']));
        $this->clients['mongodb://q:2']->method('selectDatabase')->willThrowException(new \RuntimeException('no auth'));

        $connections = $this->inspector()->getOverview()['connections'];

        self::assertSame('cannot reach mongodb://***@down:1', $connections[0]['error']);
        self::assertSame([], $connections[0]['databases']);
        // The failing collection count is best-effort and reads as 0.
        self::assertSame(0, $connections[1]['databases'][0]['collections']);
        self::assertArrayNotHasKey('error', $connections[1]);
    }

    public function testAnUnavailableBuildInfoDoesNotFailTheOverview(): void
    {
        $client = $this->clientFor('mongodb://priv:27017', []);
        $client->method('listDatabases')->willReturn($this->databases(['shop']));
        $client->method('selectDatabase')->willThrowException(new \RuntimeException('x'));
        // no buildInfo handler registered => the command throws

        $connection = $this->inspector()->getOverview()['connections'][0];

        self::assertSame([], $connection['server']);
        self::assertCount(1, $connection['databases']);
        self::assertArrayNotHasKey('error', $connection);
    }

    public function testAVersionlessBuildInfoReportsAnEmptyVersion(): void
    {
        $this->commands['buildInfo'] = static fn () => [(object) ['other' => 1]];
        $this->clientFor('mongodb://priv:27017', [])->method('listDatabases')->willReturn($this->databases([]));

        self::assertSame(['Version' => ''], $this->inspector()->getOverview()['connections'][0]['server']);
    }

    public function testThePasswordFieldIsHandedToTheDriverNotSplicedIntoTheDsn(): void
    {
        $this->settings['aaxis_devtools.mongodb_viewer_private_password'] = 'enc';
        $this->commands['buildInfo'] = static fn () => [];
        $this->clientFor('mongodb://priv:27017', [])->method('listDatabases')->willReturn($this->databases([]));
        $this->created = [];

        $this->inspector()->getOverview();

        self::assertSame([['dsn' => 'mongodb://priv:27017', 'options' => ['password' => 'dec(enc)']]], $this->created);
    }

    // --- collections ---------------------------------------------------------

    public function testCollectionsAreCountedAndSortedCaseInsensitively(): void
    {
        $database = $this->createMock(Database::class);
        $database->method('listCollections')->willReturn($this->collectionInfos(['orders', 'Accounts', 'events']));
        $database->method('selectCollection')->willReturnCallback(function (string $name): Collection {
            $collection = $this->createMock(Collection::class);
            if ($name === 'events') {
                $collection->method('estimatedDocumentCount')->willThrowException(new \RuntimeException('views have no estimates'));
            } else {
                $collection->method('estimatedDocumentCount')->willReturn(\strlen($name));
            }

            return $collection;
        });
        $client = $this->clientFor('mongodb://priv:27017', []);
        $client->expects(self::once())->method('selectDatabase')->with('shop')->willReturn($database);

        self::assertSame(
            [['name' => 'Accounts', 'count' => 8], ['name' => 'events', 'count' => 0], ['name' => 'orders', 'count' => 6]],
            $this->inspector()->listCollections('private', 'shop')
        );
    }

    public function testListingCollectionsOfAnUnconfiguredConnectionFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No MongoDB DSN is configured for the "public" connection.');

        $this->inspector()->listCollections('public', 'shop');
    }

    // --- documents -----------------------------------------------------------

    private function collection(): Collection&MockObject
    {
        $collection = $this->createMock(Collection::class);
        $database = $this->createMock(Database::class);
        $database->method('selectCollection')->with('orders')->willReturn($collection);
        $this->clientFor('mongodb://priv:27017', [])->method('selectDatabase')->with('shop')->willReturn($database);

        return $collection;
    }

    public function testFindDocumentsAppliesTheFilterAndPaging(): void
    {
        $collection = $this->collection();
        $collection->expects(self::once())->method('countDocuments')->with(['status' => 'open'])->willReturn(5);
        $collection->expects(self::once())->method('find')->with(['status' => 'open'], ['skip' => 1, 'limit' => 2])
            ->willReturn(new FakeMongoCursor([
                new BSONDocument(['_id' => 'a', 'n' => 1]),
                new BSONDocument(['_id' => 'b', 'nested' => new BSONDocument(['x' => [1, 2]])]),
            ]));

        $result = $this->inspector()->findDocuments('private', 'shop', 'orders', ' {"status":"open"} ', 1, 2);

        self::assertSame(5, $result['total']);
        self::assertTrue($result['truncated']);
        self::assertSame([['_id' => 'a', 'n' => 1], ['_id' => 'b', 'nested' => ['x' => [1, 2]]]], $result['documents']);
    }

    public function testTheLastPageIsNotTruncated(): void
    {
        $collection = $this->collection();
        $collection->method('countDocuments')->willReturn(3);
        $collection->method('find')->willReturn(new FakeMongoCursor([new BSONDocument(['n' => 3])]));

        $result = $this->inspector()->findDocuments('private', 'shop', 'orders', '', 2, 10);

        self::assertFalse($result['truncated']);
    }

    /**
     * @dataProvider emptyFilterProvider
     */
    public function testAnEmptyFilterMatchesEverything(string $filter): void
    {
        $collection = $this->collection();
        $collection->expects(self::once())->method('countDocuments')->with([])->willReturn(0);
        $collection->expects(self::once())->method('find')->with([], self::anything())->willReturn(new FakeMongoCursor([]));

        $result = $this->inspector()->findDocuments('private', 'shop', 'orders', $filter, 0, 5);

        self::assertSame(['total' => 0, 'documents' => [], 'truncated' => false], $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyFilterProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'braces' => ['{}'];
        yield 'padded braces' => [" {} \n"];
    }

    /**
     * @dataProvider pagingProvider
     */
    public function testPagingIsClamped(int $skip, int $limit, int $expectedSkip, int $expectedLimit): void
    {
        $collection = $this->collection();
        $collection->method('countDocuments')->willReturn(0);
        $collection->expects(self::once())->method('find')
            ->with([], ['skip' => $expectedSkip, 'limit' => $expectedLimit])->willReturn(new FakeMongoCursor([]));

        $this->inspector()->findDocuments('private', 'shop', 'orders', '', $skip, $limit);
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function pagingProvider(): iterable
    {
        yield 'negative skip' => [-4, 10, 0, 10];
        yield 'zero limit' => [0, 0, 0, 1];
        yield 'negative limit' => [0, -9, 0, 1];
        yield 'limit above the cap' => [5, 1000, 5, MongoInspector::MAX_LIMIT];
        yield 'in range' => [20, 50, 20, 50];
    }

    /**
     * @dataProvider invalidFilterProvider
     */
    public function testAnInvalidFilterIsRejected(string $filter): void
    {
        $this->collection();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The filter must be a valid JSON object.');

        $this->inspector()->findDocuments('private', 'shop', 'orders', $filter, 0, 5);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFilterProvider(): iterable
    {
        yield 'not json' => ['{oops'];
        yield 'scalar' => ['5'];
        yield 'string' => ['"x"'];
        yield 'null literal' => ['null'];
    }

    // --- connection test -----------------------------------------------------

    public function testTestConnectionWithoutAnyDsn(): void
    {
        self::assertSame([
            'success' => false,
            'message' => 'No MongoDB DSN is configured (neither public nor private).',
            'details' => [],
        ], $this->inspector('')->testConnection());
    }

    public function testTestConnectionPingsEveryConnectionAndReportsTheVersion(): void
    {
        $this->settings['aaxis_devtools.mongodb_viewer_public_dsn'] = 'mongodb://pub:2';
        $this->commands['ping'] = static fn () => [(object) ['ok' => 1.0]];
        $this->commands['buildInfo'] = static fn (Client $client) => [(object) ['version' => '7.0.5']];

        $result = $this->inspector('mongodb://user:secret@priv:1/db')->testConnection();

        self::assertSame([
            'success' => true,
            'message' => 'MongoDB connection succeeded.',
            'details' => [
                'Private' => 'mongodb://***@priv:1/db (v7.0.5)',
                'Public' => 'mongodb://pub:2 (v7.0.5)',
            ],
        ], $result);
        self::assertStringNotContainsString('secret', json_encode($result));
    }

    public function testTestConnectionWithoutABuildVersion(): void
    {
        $this->commands['ping'] = static fn () => [(object) ['ok' => 1]];
        $this->commands['buildInfo'] = static fn () => [];

        $result = $this->inspector()->testConnection();

        self::assertSame(['Private' => 'mongodb://priv:27017'], $result['details']);
    }

    /**
     * @dataProvider unacknowledgedPingProvider
     *
     * @param array<int, object> $pingResult
     */
    public function testAnUnacknowledgedPingIsAFailure(array $pingResult): void
    {
        $this->commands['ping'] = static fn () => $pingResult;
        $this->commands['buildInfo'] = static function (): array {
            self::fail('buildInfo must not run after a failed ping.');
        };

        $result = $this->inspector()->testConnection();

        self::assertFalse($result['success']);
        self::assertSame('MongoDB connection failed: Private: MongoDB did not acknowledge the ping.', $result['message']);
        self::assertSame(['Private' => 'mongodb://priv:27017 — not acknowledged'], $result['details']);
    }

    /**
     * @return iterable<string, array{array<int, object>}>
     */
    public static function unacknowledgedPingProvider(): iterable
    {
        yield 'ok is zero' => [[(object) ['ok' => 0]]];
        yield 'no result' => [[]];
        yield 'no ok field' => [[(object) ['x' => 1]]];
    }

    public function testDriverErrorsAreRedactedAndDoNotStopTheOtherConnections(): void
    {
        $this->settings['aaxis_devtools.mongodb_viewer_public_dsn'] = 'mongodb://pub:2';
        $this->unreachable = ['mongodb://a:b@priv:1'];
        $this->commands['ping'] = static fn () => [(object) ['ok' => 1]];
        $this->commands['buildInfo'] = static fn () => [(object) ['version' => '5.0']];

        $result = $this->inspector('mongodb://a:b@priv:1')->testConnection();

        self::assertFalse($result['success']);
        self::assertSame('MongoDB connection failed: Private: cannot reach mongodb://***@priv:1', $result['message']);
        self::assertSame(
            ['Private' => 'mongodb://***@priv:1 — failed', 'Public' => 'mongodb://pub:2 (v5.0)'],
            $result['details']
        );
    }

    public function testMultipleFailuresAreJoined(): void
    {
        $this->settings['aaxis_devtools.mongodb_viewer_public_dsn'] = 'mongodb://pub:2';
        $this->unreachable = ['mongodb://priv:27017', 'mongodb://pub:2'];

        $result = $this->inspector()->testConnection();

        self::assertSame(
            'MongoDB connection failed: Private: cannot reach mongodb://priv:27017 Public: cannot reach mongodb://pub:2',
            $result['message']
        );
    }

    public function testTheFormValuesOverrideTheSavedOnesWithoutBeingPersisted(): void
    {
        $this->settings['aaxis_devtools.mongodb_viewer_private_dsn'] = 'mongodb://saved:1';
        $this->settings['aaxis_devtools.mongodb_viewer_private_password'] = 'enc';
        $this->commands['ping'] = static fn () => [(object) ['ok' => 1]];
        $this->commands['buildInfo'] = static fn () => [];

        $result = $this->inspector()->testConnection([
            'private_dsn' => ' mongodb://typed:9 ',
            'private_password' => 'typed-pw',
            'public_dsn' => 'mongodb://typed-pub:8',
        ]);

        self::assertSame(['Private' => 'mongodb://typed:9', 'Public' => 'mongodb://typed-pub:8'], $result['details']);
        self::assertSame(['password' => 'typed-pw'], $this->created[0]['options']);
        // The public connection has no password of its own.
        self::assertSame([], $this->created[1]['options']);
    }

    /**
     * @dataProvider notEnteredPasswordProvider
     */
    public function testAMaskedOrBlankPasswordFallsBackToTheSavedOne(string $typed): void
    {
        $this->settings['aaxis_devtools.mongodb_viewer_private_password'] = 'enc';
        $this->commands['ping'] = static fn () => [(object) ['ok' => 1]];
        $this->commands['buildInfo'] = static fn () => [];

        $this->inspector()->testConnection(['private_password' => $typed]);

        self::assertSame(['password' => 'dec(enc)'], $this->created[0]['options']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notEnteredPasswordProvider(): iterable
    {
        yield 'stars' => ['********'];
        yield 'blank' => ['  '];
        yield 'empty' => [''];
    }

    public function testAnUndecryptableSavedPasswordMeansNoPassword(): void
    {
        $crypter = $this->createMock(SymmetricCrypterInterface::class);
        $crypter->method('decryptData')->willThrowException(new \RuntimeException('bad key'));
        $this->crypter = $crypter;
        $this->settings['aaxis_devtools.mongodb_viewer_private_password'] = 'garbage';
        $this->commands['ping'] = static fn () => [(object) ['ok' => 1]];
        $this->commands['buildInfo'] = static fn () => [];

        $this->inspector()->testConnection();

        self::assertSame([], $this->created[0]['options']);
    }

    public function testAnEmptyDecryptionResultMeansNoPassword(): void
    {
        $this->settings['aaxis_devtools.mongodb_viewer_private_password'] = '   ';
        $this->commands['ping'] = static fn () => [(object) ['ok' => 1]];
        $this->commands['buildInfo'] = static fn () => [];

        $this->inspector()->testConnection();

        self::assertSame([], $this->created[0]['options']);
    }

    /**
     * @dataProvider redactionProvider
     */
    public function testRedactsCredentialsEmbeddedInAnyText(string $text, string $expected): void
    {
        self::assertSame($expected, $this->inspector()->redactDsnCredentials($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function redactionProvider(): iterable
    {
        yield 'user and password' => ['failed mongodb://user:pw@host:27017/db', 'failed mongodb://***@host:27017/db'];
        yield 'user only' => ['mongodb://user@host', 'mongodb://***@host'];
        yield 'srv' => ['mongodb+srv://u:p@cluster.example.net/x', 'mongodb+srv://***@cluster.example.net/x'];
        yield 'two dsns' => ['a://x:y@h1 b://z@h2', 'a://***@h1 b://***@h2'];
        yield 'no credentials' => ['mongodb://host:27017', 'mongodb://host:27017'];
        yield 'plain message' => ['connection refused', 'connection refused'];
    }
}
