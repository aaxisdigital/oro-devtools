<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Entity;

use Aaxis\Bundle\DevToolsBundle\Entity\NetworkTestHistory;
use Aaxis\Bundle\DevToolsBundle\Entity\QueryHistory;
use Oro\Bundle\UserBundle\Entity\User;
use PHPUnit\Framework\TestCase;

class HistoryEntitiesTest extends TestCase
{
    public function testQueryHistoryStartsEmpty(): void
    {
        $history = new QueryHistory();

        self::assertNull($history->getId());
        self::assertNull($history->getUser());
        self::assertNull($history->getQuery());
        self::assertNull($history->getRecordCount());
        self::assertNull($history->getRunAt());
        self::assertNull($history->getResult());
        self::assertNull($history->getTimeMs());
    }

    public function testQueryHistoryFluentSetters(): void
    {
        $user = new User();
        $runAt = new \DateTime('2026-01-02 03:04:05', new \DateTimeZone('UTC'));

        $history = (new QueryHistory())
            ->setUser($user)
            ->setQuery('SELECT 1')
            ->setRecordCount(1)
            ->setRunAt($runAt)
            ->setResult(QueryHistory::RESULT_BLOCKED)
            ->setTimeMs(12);

        self::assertSame($user, $history->getUser());
        self::assertSame('SELECT 1', $history->getQuery());
        self::assertSame(1, $history->getRecordCount());
        self::assertSame($runAt, $history->getRunAt());
        self::assertSame('blocked', $history->getResult());
        self::assertSame(12, $history->getTimeMs());
    }

    public function testQueryHistoryAllowsClearingNullableFields(): void
    {
        $history = (new QueryHistory())->setUser(new User())->setRecordCount(3)->setResult('error')->setTimeMs(1);
        $history->setUser(null)->setRecordCount(null)->setResult(null)->setTimeMs(null);

        self::assertNull($history->getUser());
        self::assertNull($history->getRecordCount());
        self::assertNull($history->getResult());
        self::assertNull($history->getTimeMs());
    }

    public function testNetworkTestHistoryStartsEmpty(): void
    {
        $history = new NetworkTestHistory();

        self::assertNull($history->getId());
        self::assertNull($history->getUser());
        self::assertNull($history->getTest());
        self::assertNull($history->getPayload());
        self::assertNull($history->getResponse());
        self::assertNull($history->getResult());
    }

    public function testNetworkTestHistoryFluentSetters(): void
    {
        $user = new User();

        $history = (new NetworkTestHistory())
            ->setUser($user)
            ->setTest('Ping')
            ->setPayload(['host' => 'example.org'])
            ->setResponse('ok')
            ->setResult(NetworkTestHistory::RESULT_SUCCESS)
            ->setTimeMs(40)
            ->setRunAt(new \DateTimeImmutable('2026-01-01'));

        self::assertSame($user, $history->getUser());
        self::assertSame('Ping', $history->getTest());
        self::assertSame(['host' => 'example.org'], $history->getPayload());
        self::assertSame('ok', $history->getResponse());
        self::assertSame('success', $history->getResult());
        self::assertSame(40, $history->getTimeMs());
        self::assertSame('2026-01-01', $history->getRunAt()->format('Y-m-d'));
    }

    public function testNetworkTestHistoryAllowsClearingNullableFields(): void
    {
        $history = (new NetworkTestHistory())->setUser(new User())->setPayload(['a' => 1])->setResponse('x');
        $history->setUser(null)->setPayload(null)->setResponse(null);

        self::assertNull($history->getUser());
        self::assertNull($history->getPayload());
        self::assertNull($history->getResponse());
    }
}
