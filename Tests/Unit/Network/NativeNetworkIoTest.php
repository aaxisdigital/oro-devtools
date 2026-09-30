<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Network;

use Aaxis\Bundle\DevToolsBundle\Network\NativeNetworkIo;
use PHPUnit\Framework\TestCase;

/**
 * Only the paths that never leave the process are exercised here: clock, address literals and
 * requests that libcurl / the stream layer refuse before opening any socket (disallowed protocol,
 * unparsable address).
 */
class NativeNetworkIoTest extends TestCase
{
    private NativeNetworkIo $io;

    protected function setUp(): void
    {
        $this->io = new NativeNetworkIo();
    }

    public function testTheClockTracksWallTimeAndDoesNotGoBackwards(): void
    {
        $before = microtime(true);
        $first = $this->io->now();
        $second = $this->io->now();
        $after = microtime(true);

        self::assertGreaterThanOrEqual($before, $first);
        self::assertGreaterThanOrEqual($first, $second);
        self::assertLessThanOrEqual($after, $second);
    }

    public function testPausingForNoTimeReturnsImmediately(): void
    {
        $this->io->pause(0);

        $this->addToAssertionCount(1);
    }

    /**
     * @dataProvider silentProvider
     */
    public function testAnAddressLiteralResolvesToItself(bool $silent): void
    {
        self::assertSame('127.0.0.1', $this->io->resolve('127.0.0.1', $silent));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function silentProvider(): iterable
    {
        yield 'default' => [false];
        yield 'silent' => [true];
    }

    public function testATcpConnectToAnUnparsableAddressFailsWithAnExplanation(): void
    {
        [$connected, , $error] = $this->io->tcpConnect('', 0, 0.5);

        self::assertFalse($connected);
        self::assertNotSame('', $error);
    }

    /**
     * @dataProvider blockedUrlProvider
     */
    public function testCurlIsPinnedToHttpAndHttps(string $url): void
    {
        $result = $this->io->curl($url, 1);

        self::assertFalse($result['response']);
        self::assertSame([], $result['info']);
        self::assertMatchesRegularExpression('/not supported|disabled/i', $result['error']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedUrlProvider(): iterable
    {
        yield 'local files' => ['file:///etc/passwd'];
        yield 'ftp' => ['ftp://127.0.0.1/x'];
        yield 'gopher' => ['gopher://127.0.0.1/x'];
        yield 'unknown scheme' => ['foo://127.0.0.1/x'];
    }

    public function testATlsPeerLookupFailureCarriesTheReason(): void
    {
        $peer = $this->io->tlsPeer('[', 443, 1);

        self::assertFalse($peer['connected']);
        self::assertNotSame('', $peer['errstr']);
        self::assertNull($peer['cert']);
        self::assertSame([], $peer['chain']);
    }

    public function testFailedTlsHandshakesYieldNothing(): void
    {
        self::assertNull($this->io->tlsCrypto('[', 443, 1));
        self::assertFalse($this->io->tlsHandshake('[', 443, 1, ['verify_peer' => false, 'ciphers' => 'AES128-SHA']));
    }
}
