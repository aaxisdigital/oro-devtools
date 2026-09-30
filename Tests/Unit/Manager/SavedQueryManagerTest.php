<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Manager;

use Aaxis\Bundle\DevToolsBundle\Entity\Repository\SavedQueryRepository;
use Aaxis\Bundle\DevToolsBundle\Entity\SavedQuery;
use Aaxis\Bundle\DevToolsBundle\Exception\DuplicateQueryNameException;
use Aaxis\Bundle\DevToolsBundle\Manager\SavedQueryManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use Oro\Bundle\UserBundle\Entity\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SavedQueryManagerTest extends TestCase
{
    private ManagerRegistry&MockObject $doctrine;
    private TokenAccessorInterface&MockObject $tokenAccessor;
    private EntityManagerInterface&MockObject $em;
    private SavedQueryRepository&MockObject $repository;
    private SavedQueryManager $manager;

    protected function setUp(): void
    {
        $this->doctrine = $this->createMock(ManagerRegistry::class);
        $this->tokenAccessor = $this->createMock(TokenAccessorInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(SavedQueryRepository::class);
        $this->doctrine->method('getManagerForClass')->with(SavedQuery::class)->willReturn($this->em);
        $this->doctrine->method('getRepository')->with(SavedQuery::class)->willReturn($this->repository);
        $this->manager = new SavedQueryManager($this->doctrine, $this->tokenAccessor);
    }

    private function user(int $id): User
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }

    private function savedQuery(?User $owner, bool $public = false, string $name = 'Mine', string $tool = SavedQuery::TOOL_DATABASE, ?int $id = 5): SavedQuery
    {
        $query = (new SavedQuery())->setUser($owner)->setPublic($public)->setQueryName($name)->setTool($tool);
        (new \ReflectionProperty(SavedQuery::class, 'id'))->setValue($query, $id);

        return $query;
    }

    public function testCreatePersistsAPrivateQueryForTheCurrentUser(): void
    {
        $user = $this->user(7);
        $this->tokenAccessor->method('getUser')->willReturn($user);
        $this->repository->expects(self::once())->method('existsPrivateWithName')
            ->with('database', 7, 'Orders', null)->willReturn(false);
        $this->repository->expects(self::never())->method('existsPublicWithName');
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(SavedQuery::class));
        $this->em->expects(self::once())->method('flush');

        $entity = $this->manager->create('  Orders  ', 'SELECT 1', false);

        self::assertSame('Orders', $entity->getQueryName());
        self::assertSame('SELECT 1', $entity->getQueryText());
        self::assertFalse($entity->isPublic());
        self::assertSame(SavedQuery::TOOL_DATABASE, $entity->getTool());
        self::assertSame($user, $entity->getUser());
        self::assertSame($entity->getCreatedAt(), $entity->getUpdatedAt());
        self::assertSame('UTC', $entity->getCreatedAt()->getTimezone()->getName());
    }

    public function testCreateChecksPublicNamesAcrossUsersAndKeepsTheTool(): void
    {
        $this->tokenAccessor->method('getUser')->willReturn($this->user(7));
        $this->repository->expects(self::once())->method('existsPublicWithName')
            ->with('elastic', 'Shared', null)->willReturn(false);
        $this->repository->expects(self::never())->method('existsPrivateWithName');

        $entity = $this->manager->create('Shared', 'FROM x', true, SavedQuery::TOOL_ELASTIC);

        self::assertTrue($entity->isPublic());
        self::assertSame('elastic', $entity->getTool());
    }

    public function testCreateRejectsADuplicatePublicName(): void
    {
        $this->tokenAccessor->method('getUser')->willReturn($this->user(7));
        $this->repository->method('existsPublicWithName')->willReturn(true);
        $this->em->expects(self::never())->method('persist');

        $this->expectException(DuplicateQueryNameException::class);
        $this->expectExceptionMessage('A public query named "Shared" already exists.');

        $this->manager->create('Shared', 'SELECT 1', true);
    }

    public function testCreateRejectsADuplicatePrivateName(): void
    {
        $this->tokenAccessor->method('getUser')->willReturn($this->user(7));
        $this->repository->method('existsPrivateWithName')->willReturn(true);

        $this->expectException(DuplicateQueryNameException::class);
        $this->expectExceptionMessage('You already have a private query named "Mine".');

        $this->manager->create('Mine', 'SELECT 1', false);
    }

    public function testCreateWithoutAUserSkipsThePrivateUniquenessCheckAndLeavesTheOwnerEmpty(): void
    {
        $this->tokenAccessor->method('getUser')->willReturn(null);
        $this->repository->expects(self::never())->method('existsPrivateWithName');

        $entity = $this->manager->create('Mine', 'SELECT 1', false);

        self::assertNull($entity->getUser());
    }

    /**
     * @dataProvider nameProvider
     */
    public function testCreateNormalizesTheName(string $given, string $expected): void
    {
        $this->tokenAccessor->method('getUser')->willReturn(null);

        self::assertSame($expected, $this->manager->create($given, 'q', false)->getQueryName());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nameProvider(): iterable
    {
        yield 'trimmed' => ["  spaced \n", 'spaced'];
        yield 'blank falls back to a default' => ['   ', 'Untitled query'];
        yield 'truncated to the column length' => [str_repeat('é', 60), str_repeat('é', SavedQuery::NAME_MAX_LENGTH)];
    }

    public function testUpdateChangesEveryProvidedFieldAndExcludesItselfFromTheUniquenessCheck(): void
    {
        $owner = $this->user(7);
        $entity = $this->savedQuery($owner, false, 'Old', SavedQuery::TOOL_ELASTIC, 5);
        $entity->setQueryText('old text');
        $this->repository->expects(self::once())->method('existsPublicWithName')
            ->with('elastic', 'New', 5)->willReturn(false);
        $this->em->expects(self::once())->method('flush');

        $result = $this->manager->update($entity, ' New ', true, 'new text');

        self::assertSame($entity, $result);
        self::assertSame('New', $entity->getQueryName());
        self::assertTrue($entity->isPublic());
        self::assertSame('new text', $entity->getQueryText());
        self::assertEqualsWithDelta(time(), $entity->getUpdatedAt()->getTimestamp(), 5);
    }

    public function testUpdateKeepsCurrentValuesWhenNullIsPassed(): void
    {
        $owner = $this->user(7);
        $entity = $this->savedQuery($owner, false, 'Keep');
        $entity->setQueryText('keep text');
        $this->repository->expects(self::once())->method('existsPrivateWithName')
            ->with('database', 7, 'Keep', 5)->willReturn(false);

        $this->manager->update($entity, null, null, null);

        self::assertSame('Keep', $entity->getQueryName());
        self::assertFalse($entity->isPublic());
        self::assertSame('keep text', $entity->getQueryText());
    }

    public function testUpdateRejectsACollidingName(): void
    {
        $entity = $this->savedQuery($this->user(7));
        $this->repository->method('existsPrivateWithName')->willReturn(true);
        $this->em->expects(self::never())->method('flush');

        $this->expectException(DuplicateQueryNameException::class);

        $this->manager->update($entity, 'Taken', null, null);
    }

    public function testUpdateOfAnOrphanedPrivateQuerySkipsTheUniquenessCheck(): void
    {
        $entity = $this->savedQuery(null);
        $this->repository->expects(self::never())->method('existsPrivateWithName');

        $this->manager->update($entity, 'Renamed', null, null);

        self::assertSame('Renamed', $entity->getQueryName());
    }

    public function testMenuDataReportsWhetherThereIsMoreThanTheMenuLimit(): void
    {
        $public = [$this->savedQuery(null, true)];
        $private = [$this->savedQuery($this->user(7))];
        $this->repository->method('findRecentPublic')->with('elastic', SavedQueryManager::MENU_LIMIT)->willReturn($public);
        $this->repository->method('findRecentPrivateForUser')->with('elastic', 7, SavedQueryManager::MENU_LIMIT)->willReturn($private);
        $this->repository->method('countPublic')->with('elastic')->willReturn(11);
        $this->repository->method('countPrivateForUser')->with('elastic', 7)->willReturn(10);

        $data = $this->manager->getMenuData(7, SavedQuery::TOOL_ELASTIC);

        self::assertSame($public, $data['public']);
        self::assertSame($private, $data['private']);
        self::assertTrue($data['hasMorePublic']);
        self::assertFalse($data['hasMorePrivate']);
    }

    public function testMenuDataDefaultsToTheDatabaseTool(): void
    {
        $this->repository->expects(self::once())->method('findRecentPublic')->with('database', 10)->willReturn([]);
        $this->repository->method('findRecentPrivateForUser')->willReturn([]);

        self::assertFalse($this->manager->getMenuData(1)['hasMorePublic']);
    }

    public function testExposesTheRepository(): void
    {
        self::assertSame($this->repository, $this->manager->getRepository());
    }

    public function testCurrentUserId(): void
    {
        $this->tokenAccessor->method('getUser')->willReturn($this->user(42));

        self::assertSame(42, $this->manager->getCurrentUserId());
    }

    public function testCurrentUserIdIsNullWithoutAnOroUser(): void
    {
        $this->tokenAccessor->method('getUser')->willReturn(null);

        self::assertNull($this->manager->getCurrentUserId());
    }
}
