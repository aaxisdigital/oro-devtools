<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Entity\Repository;

use Aaxis\Bundle\DevToolsBundle\Entity\Repository\SavedQueryRepository;
use Aaxis\Bundle\DevToolsBundle\Entity\SavedQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The repository only builds DQL; these tests pin the generated statement and its bound parameters
 * (through a real QueryBuilder over a mocked EntityManager), which is what the behavior consists of.
 */
class SavedQueryRepositoryTest extends TestCase
{
    private const FROM = 'SELECT q FROM Aaxis\Bundle\DevToolsBundle\Entity\SavedQuery q ';

    private EntityManagerInterface&MockObject $em;
    private Query&MockObject $query;
    private SavedQueryRepository $repository;

    /** @var array{dql: string|null, parameters: array<string, mixed>, maxResults: int|null} */
    private array $captured = ['dql' => null, 'parameters' => [], 'maxResults' => null];

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->query = $this->createMock(Query::class);
        $this->em->method('createQueryBuilder')->willReturnCallback(fn () => new QueryBuilder($this->em));
        $this->em->method('getExpressionBuilder')->willReturn(new Expr());
        $this->em->method('createQuery')->willReturnCallback(function (string $dql): Query {
            $this->captured['dql'] = $dql;

            return $this->query;
        });
        $this->query->method('setParameters')->willReturnCallback(function ($parameters): Query {
            foreach ($parameters as $parameter) {
                $this->captured['parameters'][$parameter->getName()] = $parameter->getValue();
            }

            return $this->query;
        });
        $this->query->method('setFirstResult')->willReturnSelf();
        $this->query->method('setMaxResults')->willReturnCallback(function (?int $max): Query {
            $this->captured['maxResults'] = $max;

            return $this->query;
        });
        $this->repository = new SavedQueryRepository($this->em, new ClassMetadata(SavedQuery::class));
    }

    public function testFindRecentPublic(): void
    {
        $found = [new SavedQuery()];
        $this->query->method('getResult')->willReturn($found);

        self::assertSame($found, $this->repository->findRecentPublic('elastic', 10));

        self::assertSame(
            self::FROM . 'WHERE q.tool = :tool AND q.public = true ORDER BY q.createdAt DESC',
            $this->captured['dql']
        );
        self::assertSame(['tool' => 'elastic'], $this->captured['parameters']);
        self::assertSame(10, $this->captured['maxResults']);
    }

    public function testFindRecentPrivateForUser(): void
    {
        $found = [new SavedQuery()];
        $this->query->method('getResult')->willReturn($found);

        self::assertSame($found, $this->repository->findRecentPrivateForUser('database', 7, 5));

        self::assertSame(
            self::FROM . 'WHERE q.tool = :tool AND q.public = false AND q.user = :userId ORDER BY q.createdAt DESC',
            $this->captured['dql']
        );
        self::assertSame(['tool' => 'database', 'userId' => 7], $this->captured['parameters']);
        self::assertSame(5, $this->captured['maxResults']);
    }

    public function testCountPublic(): void
    {
        $this->query->method('getSingleScalarResult')->willReturn('12');

        self::assertSame(12, $this->repository->countPublic('database'));

        self::assertSame(
            'SELECT COUNT(q.id) FROM Aaxis\Bundle\DevToolsBundle\Entity\SavedQuery q WHERE q.tool = :tool AND q.public = true',
            $this->captured['dql']
        );
        self::assertSame(['tool' => 'database'], $this->captured['parameters']);
    }

    public function testCountPrivateForUser(): void
    {
        $this->query->method('getSingleScalarResult')->willReturn(3);

        self::assertSame(3, $this->repository->countPrivateForUser('elastic', 9));

        self::assertSame(
            'SELECT COUNT(q.id) FROM Aaxis\Bundle\DevToolsBundle\Entity\SavedQuery q '
            . 'WHERE q.tool = :tool AND q.public = false AND q.user = :userId',
            $this->captured['dql']
        );
        self::assertSame(['tool' => 'elastic', 'userId' => 9], $this->captured['parameters']);
    }

    /**
     * @dataProvider existsProvider
     */
    public function testExistsPublicWithName(mixed $count, ?int $exclude, bool $expected, string $extraDql): void
    {
        $this->query->method('getSingleScalarResult')->willReturn($count);

        self::assertSame($expected, $this->repository->existsPublicWithName('database', 'Top', $exclude));

        self::assertSame(
            'SELECT COUNT(q.id) FROM Aaxis\Bundle\DevToolsBundle\Entity\SavedQuery q '
            . 'WHERE q.tool = :tool AND q.public = true AND q.queryName = :name' . $extraDql,
            $this->captured['dql']
        );
        self::assertSame(
            ['tool' => 'database', 'name' => 'Top'] + ($exclude !== null ? ['id' => $exclude] : []),
            $this->captured['parameters']
        );
    }

    /**
     * @return iterable<string, array{mixed, int|null, bool, string}>
     */
    public static function existsProvider(): iterable
    {
        yield 'found' => ['1', null, true, ''];
        yield 'none' => ['0', null, false, ''];
        yield 'found excluding one row' => [2, 44, true, ' AND q.id <> :id'];
        yield 'only the excluded row exists' => [0, 44, false, ' AND q.id <> :id'];
    }

    /**
     * @dataProvider existsProvider
     */
    public function testExistsPrivateWithName(mixed $count, ?int $exclude, bool $expected, string $extraDql): void
    {
        $this->query->method('getSingleScalarResult')->willReturn($count);

        self::assertSame($expected, $this->repository->existsPrivateWithName('elastic', 7, 'Top', $exclude));

        self::assertSame(
            'SELECT COUNT(q.id) FROM Aaxis\Bundle\DevToolsBundle\Entity\SavedQuery q '
            . 'WHERE q.tool = :tool AND q.public = false AND q.user = :userId AND q.queryName = :name' . $extraDql,
            $this->captured['dql']
        );
        self::assertSame(
            ['tool' => 'elastic', 'userId' => 7, 'name' => 'Top'] + ($exclude !== null ? ['id' => $exclude] : []),
            $this->captured['parameters']
        );
    }

    public function testFindAllVisibleForUserReturnsEveryPublicQueryAndTheUsersOwn(): void
    {
        $found = [new SavedQuery(), new SavedQuery()];
        $this->query->method('getResult')->willReturn($found);

        self::assertSame($found, $this->repository->findAllVisibleForUser('database', 7));

        self::assertSame(
            self::FROM . 'WHERE q.tool = :tool AND (q.public = true OR q.user = :userId) ORDER BY q.createdAt DESC',
            $this->captured['dql']
        );
        self::assertSame(['tool' => 'database', 'userId' => 7], $this->captured['parameters']);
        self::assertNull($this->captured['maxResults']);
    }
}
