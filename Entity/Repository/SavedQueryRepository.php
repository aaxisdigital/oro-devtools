<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Entity\Repository;

use Aaxis\Bundle\DevToolsBundle\Entity\SavedQuery;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<SavedQuery>
 *
 * All lookups are scoped by {@see SavedQuery} `tool` so the Database Viewer and the Elastic Viewer
 * share the table without seeing each other's saved queries.
 */
class SavedQueryRepository extends EntityRepository
{
    private const string BY_TOOL = 'q.tool = :tool';
    private const string IS_PUBLIC = 'q.public = true';
    private const string IS_PRIVATE = 'q.public = false';
    private const string BY_USER = 'q.user = :userId';
    private const string COUNT_IDS = 'COUNT(q.id)';

    /**
     * @return SavedQuery[]
     */
    public function findRecentPublic(string $tool, int $limit): array
    {
        return $this->createQueryBuilder('q')
            ->where(self::BY_TOOL)
            ->andWhere(self::IS_PUBLIC)
            ->setParameter('tool', $tool)
            ->orderBy('q.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return SavedQuery[]
     */
    public function findRecentPrivateForUser(string $tool, int $userId, int $limit): array
    {
        return $this->createQueryBuilder('q')
            ->where(self::BY_TOOL)
            ->andWhere(self::IS_PRIVATE)
            ->andWhere(self::BY_USER)
            ->setParameter('tool', $tool)
            ->setParameter('userId', $userId)
            ->orderBy('q.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countPublic(string $tool): int
    {
        return (int) $this->createQueryBuilder('q')
            ->select(self::COUNT_IDS)
            ->where(self::BY_TOOL)
            ->andWhere(self::IS_PUBLIC)
            ->setParameter('tool', $tool)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countPrivateForUser(string $tool, int $userId): int
    {
        return (int) $this->createQueryBuilder('q')
            ->select(self::COUNT_IDS)
            ->where(self::BY_TOOL)
            ->andWhere(self::IS_PRIVATE)
            ->andWhere(self::BY_USER)
            ->setParameter('tool', $tool)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function existsPublicWithName(string $tool, string $name, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('q')
            ->select(self::COUNT_IDS)
            ->where(self::BY_TOOL)
            ->andWhere(self::IS_PUBLIC)
            ->andWhere('q.queryName = :name')
            ->setParameter('tool', $tool)
            ->setParameter('name', $name);
        if (null !== $excludeId) {
            $qb->andWhere('q.id <> :id')->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function existsPrivateWithName(string $tool, int $userId, string $name, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('q')
            ->select(self::COUNT_IDS)
            ->where(self::BY_TOOL)
            ->andWhere(self::IS_PRIVATE)
            ->andWhere(self::BY_USER)
            ->andWhere('q.queryName = :name')
            ->setParameter('tool', $tool)
            ->setParameter('userId', $userId)
            ->setParameter('name', $name);
        if (null !== $excludeId) {
            $qb->andWhere('q.id <> :id')->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * All queries of the given tool visible to the user: every public query plus the user's own
     * private ones.
     *
     * @return SavedQuery[]
     */
    public function findAllVisibleForUser(string $tool, int $userId): array
    {
        $qb = $this->createQueryBuilder('q');

        return $qb
            ->where(self::BY_TOOL)
            ->andWhere($qb->expr()->orX(self::IS_PUBLIC, self::BY_USER))
            ->setParameter('tool', $tool)
            ->setParameter('userId', $userId)
            ->orderBy('q.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
