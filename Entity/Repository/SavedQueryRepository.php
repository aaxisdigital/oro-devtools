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
    /**
     * @return SavedQuery[]
     */
    public function findRecentPublic(string $tool, int $limit): array
    {
        return $this->createQueryBuilder('q')
            ->where('q.tool = :tool')
            ->andWhere('q.public = true')
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
            ->where('q.tool = :tool')
            ->andWhere('q.public = false')
            ->andWhere('q.user = :userId')
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
            ->select('COUNT(q.id)')
            ->where('q.tool = :tool')
            ->andWhere('q.public = true')
            ->setParameter('tool', $tool)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countPrivateForUser(string $tool, int $userId): int
    {
        return (int) $this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->where('q.tool = :tool')
            ->andWhere('q.public = false')
            ->andWhere('q.user = :userId')
            ->setParameter('tool', $tool)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function existsPublicWithName(string $tool, string $name, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->where('q.tool = :tool')
            ->andWhere('q.public = true')
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
            ->select('COUNT(q.id)')
            ->where('q.tool = :tool')
            ->andWhere('q.public = false')
            ->andWhere('q.user = :userId')
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
            ->where('q.tool = :tool')
            ->andWhere($qb->expr()->orX('q.public = true', 'q.user = :userId'))
            ->setParameter('tool', $tool)
            ->setParameter('userId', $userId)
            ->orderBy('q.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
