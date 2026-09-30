<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Manager;

use Aaxis\Bundle\DevToolsBundle\Entity\QueryHistory;
use Aaxis\Bundle\DevToolsBundle\Manager\QueryHistoryManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use Oro\Bundle\UserBundle\Entity\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

class QueryHistoryManagerTest extends TestCase
{
    private ManagerRegistry&MockObject $doctrine;
    private TokenAccessorInterface&MockObject $tokenAccessor;
    private EntityManagerInterface&MockObject $em;
    private QueryHistoryManager $manager;

    protected function setUp(): void
    {
        $this->doctrine = $this->createMock(ManagerRegistry::class);
        $this->tokenAccessor = $this->createMock(TokenAccessorInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->doctrine->method('getManagerForClass')->with(QueryHistory::class)->willReturn($this->em);
        $this->manager = new QueryHistoryManager($this->doctrine, $this->tokenAccessor);
    }

    public function testStartPersistsAndFlushesANewRecordOwnedByTheCurrentUser(): void
    {
        $user = new User();
        $this->tokenAccessor->method('getUser')->willReturn($user);
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(QueryHistory::class));
        $this->em->expects(self::once())->method('flush');

        $record = $this->manager->start('SELECT 1');

        self::assertSame('SELECT 1', $record->getQuery());
        self::assertSame($user, $record->getUser());
        self::assertNull($record->getResult());
        self::assertSame('UTC', $record->getRunAt()->getTimezone()->getName());
        self::assertEqualsWithDelta(time(), $record->getRunAt()->getTimestamp(), 5);
    }

    /**
     * @dataProvider nonOroUserProvider
     */
    public function testStartLeavesTheOwnerEmptyWithoutAnOroUser(?object $user): void
    {
        $this->tokenAccessor->method('getUser')->willReturn($user);

        self::assertNull($this->manager->start('SELECT 1')->getUser());
    }

    /**
     * @return iterable<string, array{object|null}>
     */
    public static function nonOroUserProvider(): iterable
    {
        yield 'anonymous' => [null];
        yield 'api user' => [new InMemoryUser('api', null)];
    }

    public function testFinishStoresTheOutcomeAndFlushes(): void
    {
        $this->em->expects(self::once())->method('flush');
        $record = new QueryHistory();

        $this->manager->finish($record, QueryHistory::RESULT_SUCCESS, 25, 3);

        self::assertSame('success', $record->getResult());
        self::assertSame(25, $record->getTimeMs());
        self::assertSame(3, $record->getRecordCount());
    }

    public function testFinishAcceptsAMissingRecordCount(): void
    {
        $record = (new QueryHistory())->setRecordCount(9);

        $this->manager->finish($record, QueryHistory::RESULT_ERROR, 1, null);

        self::assertNull($record->getRecordCount());
        self::assertSame('error', $record->getResult());
    }
}
