<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Storage;

use Aaxis\Bundle\DevToolsBundle\Storage\ObjectKey;
use Aaxis\Bundle\DevToolsBundle\Storage\SizeFormatter;
use PHPUnit\Framework\TestCase;

class StorageHelpersTest extends TestCase
{
    /**
     * @dataProvider sizeProvider
     */
    public function testFormatsByteSizes(int $bytes, string $expected): void
    {
        self::assertSame($expected, SizeFormatter::format($bytes));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function sizeProvider(): iterable
    {
        yield 'zero' => [0, '0 B'];
        yield 'bytes' => [1023, '1023 B'];
        yield 'one kilobyte' => [1024, '1.0 KB'];
        yield 'fractional kilobytes' => [1536, '1.5 KB'];
        yield 'megabytes' => [2 * 1024 ** 2, '2.0 MB'];
        yield 'gigabytes' => [3 * 1024 ** 3, '3.0 GB'];
        yield 'terabytes' => [4 * 1024 ** 4, '4.0 TB'];
        yield 'beyond terabytes stays in terabytes' => [2048 * 1024 ** 4, '2,048.0 TB'];
    }

    /**
     * @dataProvider prefixProvider
     */
    public function testNormalizesPathsToFolderPrefixes(string $path, string $expected): void
    {
        self::assertSame($expected, ObjectKey::normalizePrefix($path));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function prefixProvider(): iterable
    {
        yield 'root' => ['', ''];
        yield 'blank' => ['  ', ''];
        yield 'only slashes' => ['///', ''];
        yield 'plain' => ['a', 'a/'];
        yield 'leading slash' => ['/a/b', 'a/b/'];
        yield 'already a prefix' => ['a/b/', 'a/b/'];
        yield 'padded' => ['  x/y  ', 'x/y/'];
    }

    /**
     * @dataProvider parentProvider
     */
    public function testFindsTheParentFolder(string $prefix, ?string $expected): void
    {
        self::assertSame($expected, ObjectKey::parentPrefix($prefix));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function parentProvider(): iterable
    {
        yield 'root has none' => ['', null];
        yield 'top level' => ['a/', ''];
        yield 'nested' => ['a/b/c/', 'a/b/'];
        yield 'without trailing slash' => ['a/b', 'a/'];
    }

    /**
     * @dataProvider basenameProvider
     */
    public function testTakesTheLastSegment(string $key, string $expected): void
    {
        self::assertSame($expected, ObjectKey::basename($key));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function basenameProvider(): iterable
    {
        yield 'file' => ['a/b/c.txt', 'c.txt'];
        yield 'top level file' => ['c.txt', 'c.txt'];
        yield 'folder prefix' => ['a/b/', 'b'];
        yield 'top level folder' => ['a/', 'a'];
        yield 'empty' => ['', ''];
    }
}
