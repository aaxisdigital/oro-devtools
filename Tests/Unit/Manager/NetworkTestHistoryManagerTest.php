<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Manager;

use Aaxis\Bundle\DevToolsBundle\Entity\NetworkTestHistory;
use Aaxis\Bundle\DevToolsBundle\Manager\NetworkTestHistoryManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use Oro\Bundle\UserBundle\Entity\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

class NetworkTestHistoryManagerTest extends TestCase
{
    private TokenAccessorInterface&MockObject $tokenAccessor;
    private EntityManagerInterface&MockObject $em;
    private NetworkTestHistoryManager $manager;

    protected function setUp(): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $this->tokenAccessor = $this->createMock(TokenAccessorInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $doctrine->method('getManagerForClass')->with(NetworkTestHistory::class)->willReturn($this->em);
        $this->manager = new NetworkTestHistoryManager($doctrine, $this->tokenAccessor);
    }

    public function testStartPersistsAndFlushesANewRecordOwnedByTheCurrentUser(): void
    {
        $user = new User();
        $this->tokenAccessor->method('getUser')->willReturn($user);
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(NetworkTestHistory::class));
        $this->em->expects(self::once())->method('flush');

        $record = $this->manager->start('Ping', ['host' => 'example.org']);

        self::assertSame('Ping', $record->getTest());
        self::assertSame(['host' => 'example.org'], $record->getPayload());
        self::assertSame($user, $record->getUser());
        self::assertSame('UTC', $record->getRunAt()->getTimezone()->getName());
    }

    /**
     * @dataProvider nonOroUserProvider
     */
    public function testStartLeavesTheOwnerEmptyWithoutAnOroUser(?object $user): void
    {
        $this->tokenAccessor->method('getUser')->willReturn($user);

        self::assertNull($this->manager->start('DNS Lookup', [])->getUser());
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
        $record = new NetworkTestHistory();

        $this->manager->finish($record, NetworkTestHistory::RESULT_ERROR, 90, 'Error: boom');

        self::assertSame('error', $record->getResult());
        self::assertSame(90, $record->getTimeMs());
        self::assertSame('Error: boom', $record->getResponse());
    }
}
