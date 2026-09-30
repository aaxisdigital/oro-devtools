<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Connection;

use Aaxis\Bundle\DevToolsBundle\Connection\DatabaseConnectionTester;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DatabaseConnectionTesterTest extends TestCase
{
    private Connection&MockObject $connection;
    private DatabaseConnectionTester $tester;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('getParams')->willReturn([
            'driver' => 'pdo_pgsql',
            'host' => 'db',
            'port' => 5432,
            'dbname' => 'oro',
            'user' => 'app',
            'password' => 's3cret',
        ]);
        $this->tester = new DatabaseConnectionTester($this->connection);
    }

    public function testIdentifiesTheTool(): void
    {
        self::assertSame('database_viewer', $this->tester->getTool());
    }

    public function testReportsTheConfiguredTargetAndServerVersionOnSuccess(): void
    {
        $this->connection->expects(self::once())->method('executeQuery')->with('SELECT 1');
        $this->connection->method('getNativeConnection')->willReturn(new class {
            public function getAttribute(int $attribute): string
            {
                return $attribute === \PDO::ATTR_SERVER_VERSION ? '16.2' : '';
            }
        });

        $result = $this->tester->test();

        self::assertTrue($result['success']);
        self::assertSame('Database connection succeeded.', $result['message']);
        self::assertSame([
            'Driver' => 'pdo_pgsql',
            'Host' => 'db',
            'Port' => '5432',
            'Database' => 'oro',
            'User' => 'app',
            'Server version' => '16.2',
        ], $result['details']);
        self::assertStringNotContainsString('s3cret', json_encode($result));
    }

    public function testTheServerVersionIsBlankWhenTheNativeConnectionCannotTellIt(): void
    {
        $this->connection->method('getNativeConnection')->willReturn(new \stdClass());

        self::assertSame('', $this->tester->test()['details']['Server version']);
    }

    public function testTheServerVersionIsBlankWhenAskingForItThrows(): void
    {
        $this->connection->method('getNativeConnection')->willThrowException(new \RuntimeException('no native'));

        $result = $this->tester->test();

        self::assertTrue($result['success']);
        self::assertSame('', $result['details']['Server version']);
    }

    public function testTheServerVersionIsBlankWhenTheNativeConnectionIsNotAnObject(): void
    {
        $this->connection->method('getNativeConnection')->willReturn(null);

        self::assertSame('', $this->tester->test()['details']['Server version']);
    }

    public function testAFailureMessageNeverContainsThePassword(): void
    {
        $this->connection->method('executeQuery')
            ->willThrowException(new \RuntimeException('FATAL: password authentication failed (s3cret)'));

        $result = $this->tester->test();

        self::assertFalse($result['success']);
        self::assertSame('Database connection failed: FATAL: password authentication failed (***)', $result['message']);
        self::assertArrayNotHasKey('Server version', $result['details']);
        self::assertSame('db', $result['details']['Host']);
    }

    public function testAnEmptyPasswordDoesNotMangleTheMessage(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['driver' => 'pdo_pgsql']);
        $connection->method('executeQuery')->willThrowException(new \RuntimeException('connection refused'));

        $result = (new DatabaseConnectionTester($connection))->test();

        self::assertSame('Database connection failed: connection refused', $result['message']);
        self::assertSame(['Driver' => 'pdo_pgsql', 'Host' => '', 'Port' => '', 'Database' => '', 'User' => ''], $result['details']);
    }
}
