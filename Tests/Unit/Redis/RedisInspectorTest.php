<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Redis;

use Aaxis\Bundle\DevToolsBundle\Exception\RedisConnectionException;
use Aaxis\Bundle\DevToolsBundle\Redis\RedisInspector;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\FakeRedis;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RedisInspectorTest extends TestCase
{
    private ConfigManager&MockObject $config;
    private SymmetricCrypterInterface&MockObject $crypter;
    private FakeRedis $redis;
    /** @var array<string, mixed> */
    private array $settings = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigManager::class);
        $this->config->method('get')->willReturnCallback(fn (string $key) => $this->settings[$key] ?? null);
        $this->crypter = $this->createMock(SymmetricCrypterInterface::class);
        $this->crypter->method('decryptData')->willReturnCallback(static fn (string $data) => 'dec(' . $data . ')');
        $this->redis = new FakeRedis();
    }

    private function inspector(string $defaultDsn = 'redis://cache:6380'): RedisInspector
    {
        $redis = $this->redis;

        return new class ($defaultDsn, $this->config, $this->crypter, $redis) extends RedisInspector {
            public function __construct(string $dsn, ConfigManager $config, SymmetricCrypterInterface $crypter, private readonly \Redis $fake)
            {
                parent::__construct($dsn, $config, $crypter);
            }

            protected function createRedis(): \Redis
            {
                return $this->fake;
            }
        };
    }

    // --- connection handling -------------------------------------------------

    public function testConnectsToTheDefaultDsnAndSelectsTheDatabase(): void
    {
        $this->inspector()->scanKeys(5, '', '', 100);

        self::assertSame(['host' => 'cache', 'port' => 6380, 'timeout' => 2.0], $this->redis->connectedTo);
        self::assertSame([5], $this->redis->selected);
        self::assertFalse($this->redis->authCalled);
    }

    public function testTheConfiguredDsnWinsOverTheDefault(): void
    {
        $this->settings['aaxis_devtools.redis_viewer_dsn'] = '  redis://other:7000  ';

        $this->inspector()->scanKeys(0, '', '', 100);

        self::assertSame('other', $this->redis->connectedTo['host']);
        self::assertSame(7000, $this->redis->connectedTo['port']);
    }

    public function testMissingHostAndPortDefaultToLocalhost(): void
    {
        $this->inspector('')->scanKeys(0, '', '', 100);

        self::assertSame('127.0.0.1', $this->redis->connectedTo['host']);
        self::assertSame(6379, $this->redis->connectedTo['port']);
    }

    public function testAnUnreachableServerIsReportedWithItsAddress(): void
    {
        $this->redis->connectResult = false;

        $this->expectException(RedisConnectionException::class);
        $this->expectExceptionMessage('Unable to connect to Redis at cache:6380.');

        $this->inspector()->getOverview();
    }

    public function testAConnectionFailureIsStillARuntimeException(): void
    {
        $this->redis->connectResult = false;

        $this->expectException(\RuntimeException::class);

        $this->inspector()->getOverview();
    }

    /**
     * @dataProvider credentialsProvider
     *
     * @param array<string, mixed> $settings
     * @param string|string[]|null $expectedAuth
     */
    public function testAuthenticatesWithTheBestAvailableCredentials(string $dsn, array $settings, string|array|null $expectedAuth): void
    {
        $this->settings = $settings + ['aaxis_devtools.redis_viewer_dsn' => $dsn];

        $this->inspector()->scanKeys(0, '', '', 10);

        self::assertSame($expectedAuth !== null, $this->redis->authCalled);
        self::assertSame($expectedAuth, $this->redis->authArgument);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string|string[]|null}>
     */
    public static function credentialsProvider(): iterable
    {
        yield 'no credentials' => ['redis://h:1', [], null];
        yield 'saved password only' => ['redis://h:1', ['aaxis_devtools.redis_viewer_password' => 'enc'], 'dec(enc)'];
        yield 'saved password with a DSN username (ACL)' => [
            'redis://alice@h:1', ['aaxis_devtools.redis_viewer_password' => 'enc'], ['alice', 'dec(enc)'],
        ];
        yield 'username is url-decoded' => [
            'redis://a%40b@h:1', ['aaxis_devtools.redis_viewer_password' => 'enc'], ['a@b', 'dec(enc)'],
        ];
        yield 'password embedded in the DSN' => ['redis://alice:p%40ss@h:1', [], ['alice', 'p@ss']];
        yield 'password only embedded (empty user)' => ['redis://:secret@h:1', [], 'secret'];
        yield 'legacy secret in the user slot' => ['redis://legacysecret@h:1', [], 'legacysecret'];
        yield 'saved password beats the embedded one' => [
            'redis://alice:embedded@h:1', ['aaxis_devtools.redis_viewer_password' => 'enc'], ['alice', 'dec(enc)'],
        ];
    }

    public function testAnUndecryptablePasswordIsTreatedAsUnset(): void
    {
        $crypter = $this->createMock(SymmetricCrypterInterface::class);
        $crypter->method('decryptData')->willThrowException(new \RuntimeException('bad key'));
        $this->crypter = $crypter;
        $this->settings['aaxis_devtools.redis_viewer_password'] = 'garbage';

        $this->inspector()->scanKeys(0, '', '', 10);

        self::assertFalse($this->redis->authCalled);
    }

    // --- overview ------------------------------------------------------------

    public function testOverviewListsTheServerFactsAndEveryDatabase(): void
    {
        $this->redis->infoResult = [
            'redis_version' => '7.2.4',
            'redis_mode' => 'standalone',
            'os' => 'Linux',
            'uptime_in_seconds' => 3600,
            'connected_clients' => 2,
            'used_memory_human' => '1.2M',
            'maxmemory_human' => '',
            'maxmemory_policy' => 'noeviction',
            'total_commands_processed' => 99,
            'unrelated' => 'x',
        ];
        $this->redis->configResult = ['databases' => '3'];
        $this->redis->dbSizes = [0 => 10, 1 => 0, 2 => 7];

        $overview = $this->inspector()->getOverview();

        self::assertSame([
            'Version' => '7.2.4',
            'Mode' => 'standalone',
            'OS' => 'Linux',
            'Uptime (s)' => '3600',
            'Clients' => '2',
            'Memory used' => '1.2M',
            'Eviction policy' => 'noeviction',
            'Commands processed' => '99',
        ], $overview['server']);
        self::assertSame([['db' => 0, 'keys' => 10], ['db' => 1, 'keys' => 0], ['db' => 2, 'keys' => 7]], $overview['databases']);
    }

    /**
     * @dataProvider databaseCountProvider
     */
    public function testTheDatabaseCountFallsBackToSixteen(mixed $configResult, ?\Throwable $throws, int $expected): void
    {
        $this->redis->configResult = $configResult;
        $this->redis->configThrows = $throws;

        self::assertCount($expected, $this->inspector()->getOverview()['databases']);
    }

    /**
     * @return iterable<string, array{mixed, \Throwable|null, int}>
     */
    public static function databaseCountProvider(): iterable
    {
        yield 'configured' => [['databases' => '4'], null, 4];
        yield 'at least one' => [['databases' => '0'], null, 1];
        yield 'config disabled (throws)' => [false, new \RuntimeException('unknown command CONFIG'), 16];
        yield 'config returns false' => [false, null, 16];
        yield 'config without the key' => [['other' => '1'], null, 16];
    }

    // --- key scanning --------------------------------------------------------

    public function testScanReturnsTypedKeysWithTtlAndTheNextCursor(): void
    {
        $this->redis->scanResult = ['next' => 42, 'keys' => ['a', 'b', 'c', 'd', 'e', 'f']];
        $this->redis->keys = [
            'a' => ['type' => \Redis::REDIS_STRING, 'ttl' => 30, 'value' => 'x'],
            'b' => ['type' => \Redis::REDIS_LIST, 'ttl' => -1, 'value' => []],
            'c' => ['type' => \Redis::REDIS_SET, 'ttl' => -1, 'value' => []],
            'd' => ['type' => \Redis::REDIS_ZSET, 'ttl' => -1, 'value' => []],
            'e' => ['type' => \Redis::REDIS_HASH, 'ttl' => -1, 'value' => []],
            'f' => ['type' => \Redis::REDIS_STREAM, 'ttl' => -1, 'value' => []],
        ];

        $page = $this->inspector()->scanKeys(2, 'user:*', '17', 200);

        self::assertSame('42', $page['cursor']);
        self::assertFalse($page['done']);
        self::assertSame(
            [['key' => 'a', 'type' => 'string', 'ttl' => 30], ['key' => 'b', 'type' => 'list', 'ttl' => -1],
                ['key' => 'c', 'type' => 'set', 'ttl' => -1], ['key' => 'd', 'type' => 'zset', 'ttl' => -1],
                ['key' => 'e', 'type' => 'hash', 'ttl' => -1], ['key' => 'f', 'type' => 'stream', 'ttl' => -1]],
            $page['keys']
        );
        self::assertSame([17, 'user:*', 200], $this->redis->scanCall);
    }

    /**
     * @dataProvider cursorProvider
     */
    public function testAFreshScanStartsFromANullIterator(string $cursor, ?int $expectedIterator): void
    {
        $this->inspector()->scanKeys(0, '*', $cursor, 100);

        self::assertSame($expectedIterator, $this->redis->scanCall[0]);
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function cursorProvider(): iterable
    {
        yield 'empty' => ['', null];
        yield 'zero' => ['0', null];
        yield 'resumed' => ['123', 123];
    }

    public function testAnEmptyPatternMatchesEverything(): void
    {
        $this->inspector()->scanKeys(0, '', '', 100);

        self::assertSame('*', $this->redis->scanCall[1]);
    }

    /**
     * @dataProvider countProvider
     */
    public function testThePageSizeIsClamped(int $requested, int $expected): void
    {
        $this->inspector()->scanKeys(0, '*', '', $requested);

        self::assertSame($expected, $this->redis->scanCall[2]);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function countProvider(): iterable
    {
        yield 'too small' => [1, 10];
        yield 'negative' => [-5, 10];
        yield 'in range' => [250, 250];
        yield 'too big' => [50000, 1000];
    }

    public function testTheLastPageIsFlaggedAsDone(): void
    {
        $this->redis->scanResult = ['next' => 0, 'keys' => ['only']];
        $this->redis->keys['only'] = ['type' => \Redis::REDIS_STRING, 'ttl' => -1, 'value' => 'v'];

        $page = $this->inspector()->scanKeys(0, '*', '9', 100);

        self::assertTrue($page['done']);
        self::assertSame('0', $page['cursor']);
    }

    public function testAFailedScanYieldsNoKeys(): void
    {
        $this->redis->scanResult = false;

        $page = $this->inspector()->scanKeys(0, '*', '', 100);

        self::assertSame([], $page['keys']);
        self::assertTrue($page['done']);
    }

    // --- value inspection ----------------------------------------------------

    public function testAStringValue(): void
    {
        $this->redis->keys['k'] = ['type' => \Redis::REDIS_STRING, 'ttl' => 60, 'value' => 'hello'];

        self::assertSame(
            ['key' => 'k', 'type' => 'string', 'ttl' => 60, 'total' => 5, 'truncated' => false, 'value' => 'hello'],
            $this->inspector()->getValue(3, 'k')
        );
        self::assertSame([3], $this->redis->selected);
    }

    public function testALongStringIsCutAtTheByteLimit(): void
    {
        $this->redis->keys['k'] = ['type' => \Redis::REDIS_STRING, 'ttl' => -1, 'value' => str_repeat('a', RedisInspector::MAX_STRING_BYTES + 1)];

        $value = $this->inspector()->getValue(0, 'k');

        self::assertSame(RedisInspector::MAX_STRING_BYTES + 1, $value['total']);
        self::assertTrue($value['truncated']);
        self::assertSame(RedisInspector::MAX_STRING_BYTES, \strlen($value['value']));
    }

    public function testAStringOfExactlyTheLimitIsNotTruncated(): void
    {
        $this->redis->keys['k'] = ['type' => \Redis::REDIS_STRING, 'ttl' => -1, 'value' => str_repeat('a', RedisInspector::MAX_STRING_BYTES)];

        self::assertFalse($this->inspector()->getValue(0, 'k')['truncated']);
    }

    public function testAList(): void
    {
        $this->redis->keys['k'] = ['type' => \Redis::REDIS_LIST, 'ttl' => -1, 'value' => ['a', 2, 'c']];

        self::assertSame(
            ['key' => 'k', 'type' => 'list', 'ttl' => -1, 'total' => 3, 'truncated' => false, 'items' => ['a', '2', 'c']],
            $this->inspector()->getValue(0, 'k')
        );
    }

    public function testALongListIsCappedAndFlaggedAsTruncated(): void
    {
        $this->redis->keys['k'] = ['type' => \Redis::REDIS_LIST, 'ttl' => -1, 'value' => range(1, RedisInspector::MAX_ELEMENTS + 20)];

        $value = $this->inspector()->getValue(0, 'k');

        self::assertSame(RedisInspector::MAX_ELEMENTS + 20, $value['total']);
        self::assertTrue($value['truncated']);
        self::assertCount(RedisInspector::MAX_ELEMENTS, $value['items']);
    }

    public function testASet(): void
    {
        $this->redis->keys['k'] = ['type' => \Redis::REDIS_SET, 'ttl' => -1, 'value' => ['x', 'y']];

        $value = $this->inspector()->getValue(0, 'k');

        self::assertSame('set', $value['type']);
        self::assertSame(2, $value['total']);
        self::assertSame(['x', 'y'], $value['items']);
        self::assertFalse($value['truncated']);
    }

    public function testALargeSetIsCapped(): void
    {
        $this->redis->keys['k'] = ['type' => \Redis::REDIS_SET, 'ttl' => -1, 'value' => range(1, RedisInspector::MAX_ELEMENTS + 1)];

        $value = $this->inspector()->getValue(0, 'k');

        self::assertCount(RedisInspector::MAX_ELEMENTS, $value['items']);
        self::assertTrue($value['truncated']);
    }

    public function testASortedSetCarriesScores(): void
    {
        $this->redis->keys['k'] = ['type' => \Redis::REDIS_ZSET, 'ttl' => -1, 'value' => ['first' => 1.5, 'second' => 2]];

        $value = $this->inspector()->getValue(0, 'k');

        self::assertSame('zset', $value['type']);
        self::assertSame(2, $value['total']);
        self::assertSame([['member' => 'first', 'score' => 1.5], ['member' => 'second', 'score' => 2.0]], $value['items']);
        self::assertFalse($value['truncated']);
    }

    public function testALargeSortedSetIsFlaggedAsTruncated(): void
    {
        $members = [];
        for ($i = 0; $i < RedisInspector::MAX_ELEMENTS + 3; $i++) {
            $members['m' . $i] = $i;
        }
        $this->redis->keys['k'] = ['type' => \Redis::REDIS_ZSET, 'ttl' => -1, 'value' => $members];

        $value = $this->inspector()->getValue(0, 'k');

        self::assertTrue($value['truncated']);
        self::assertCount(RedisInspector::MAX_ELEMENTS, $value['items']);
    }

    public function testAHash(): void
    {
        $this->redis->keys['k'] = ['type' => \Redis::REDIS_HASH, 'ttl' => -1, 'value' => ['f1' => 'v1', 'f2' => 2]];

        $value = $this->inspector()->getValue(0, 'k');

        self::assertSame('hash', $value['type']);
        self::assertSame(2, $value['total']);
        self::assertSame([['field' => 'f1', 'value' => 'v1'], ['field' => 'f2', 'value' => '2']], $value['items']);
        self::assertFalse($value['truncated']);
    }

    public function testALargeHashIsCapped(): void
    {
        $fields = [];
        for ($i = 0; $i < RedisInspector::MAX_ELEMENTS + 5; $i++) {
            $fields['f' . $i] = 'v';
        }
        $this->redis->keys['k'] = ['type' => \Redis::REDIS_HASH, 'ttl' => -1, 'value' => $fields];

        $value = $this->inspector()->getValue(0, 'k');

        self::assertCount(RedisInspector::MAX_ELEMENTS, $value['items']);
        self::assertTrue($value['truncated']);
    }

    public function testAMissingKeyIsFlaggedAsMissing(): void
    {
        self::assertSame(
            ['key' => 'gone', 'type' => 'none', 'ttl' => -2, 'total' => 0, 'truncated' => false, 'missing' => true],
            $this->inspector()->getValue(0, 'gone')
        );
    }

    public function testAnUnsupportedTypeIsDescribed(): void
    {
        $this->redis->keys['s'] = ['type' => \Redis::REDIS_STREAM, 'ttl' => -1, 'value' => null];

        $value = $this->inspector()->getValue(0, 's');

        self::assertSame('stream', $value['type']);
        self::assertSame('(unsupported type: stream)', $value['value']);
    }

    // --- connection test -----------------------------------------------------

    public function testTestConnectionReportsHostPortAndServerFacts(): void
    {
        $this->redis->infoResult = ['redis_version' => '7.0.0', 'used_memory_human' => '900K'];

        $result = $this->inspector()->testConnection();

        self::assertSame([
            'success' => true,
            'message' => 'Redis connection succeeded.',
            'details' => ['Host' => 'cache', 'Port' => '6380', 'Version' => '7.0.0', 'Memory used' => '900K'],
        ], $result);
    }

    public function testTestConnectionHonoursTheUnsavedFormValues(): void
    {
        $result = $this->inspector()->testConnection(['dsn' => ' redis://form:6390 ', 'password' => 'typed']);

        self::assertSame('form', $result['details']['Host']);
        self::assertSame('6390', $result['details']['Port']);
        self::assertSame('typed', $this->redis->authArgument);
    }

    /**
     * @dataProvider maskedPasswordProvider
     */
    public function testAMaskedPlaceholderIsNotTakenAsAnEnteredPassword(string $typed): void
    {
        $this->settings['aaxis_devtools.redis_viewer_password'] = 'enc';

        $this->inspector()->testConnection(['password' => $typed]);

        self::assertSame('dec(enc)', $this->redis->authArgument);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function maskedPasswordProvider(): iterable
    {
        yield 'stars' => ['********'];
        yield 'padded stars' => ['  ***  '];
        yield 'blank' => ['   '];
        yield 'empty' => [''];
    }

    public function testTestConnectionDefaultsTheAddressWhenTheDsnHasNone(): void
    {
        $result = $this->inspector('')->testConnection();

        self::assertSame('127.0.0.1', $result['details']['Host']);
        self::assertSame('6379', $result['details']['Port']);
    }

    public function testAFailedConnectionTestNeverLeaksTheSecret(): void
    {
        $this->redis->infoThrows = new \RuntimeException('AUTH failed for hunter2 at redis://user:hunter2@cache:6380');
        $this->settings['aaxis_devtools.redis_viewer_password'] = 'enc';
        $crypter = $this->createMock(SymmetricCrypterInterface::class);
        $crypter->method('decryptData')->willReturn('hunter2');
        $this->crypter = $crypter;

        $result = $this->inspector()->testConnection();

        self::assertFalse($result['success']);
        self::assertSame('Redis connection failed: AUTH failed for *** at redis://***@cache:6380', $result['message']);
        self::assertStringNotContainsString('hunter2', json_encode($result));
        self::assertSame(['Host' => 'cache', 'Port' => '6380'], $result['details']);
    }

    public function testAConnectionFailureWithoutASecretStillRedactsDsnCredentials(): void
    {
        $this->redis->connectResult = false;

        $result = $this->inspector()->testConnection();

        self::assertFalse($result['success']);
        self::assertSame('Redis connection failed: Unable to connect to Redis at cache:6380.', $result['message']);
    }
}
