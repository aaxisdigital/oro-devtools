<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Entity\Repository;

use Aaxis\Bundle\DevToolsBundle\Entity\SavedQuery;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<SavedQuery>
 */
class SavedQueryRepository extends EntityRepository
{
    /**
     * @return SavedQuery[]
     */
    public function findRecentPublic(int $limit): array
    {
        return $this->createQueryBuilder('q')
            ->where('q.public = true')
            ->orderBy('q.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return SavedQuery[]
     */
    public function findRecentPrivateForUser(int $userId, int $limit): array
    {
        return $this->createQueryBuilder('q')
            ->where('q.public = false')
            ->andWhere('q.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('q.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countPublic(): int
    {
        return (int) $this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->where('q.public = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countPrivateForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->where('q.public = false')
            ->andWhere('q.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function existsPublicWithName(string $name, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->where('q.public = true')
            ->andWhere('q.queryName = :name')
            ->setParameter('name', $name);
        if (null !== $excludeId) {
            $qb->andWhere('q.id <> :id')->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function existsPrivateWithName(int $userId, string $name, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->where('q.public = false')
            ->andWhere('q.user = :userId')
            ->andWhere('q.queryName = :name')
            ->setParameter('userId', $userId)
            ->setParameter('name', $name);
        if (null !== $excludeId) {
            $qb->andWhere('q.id <> :id')->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * All queries visible to the user: every public query plus the user's own private ones.
     *
     * @return SavedQuery[]
     */
    public function findAllVisibleForUser(int $userId): array
    {
        return $this->createQueryBuilder('q')
            ->where('q.public = true')
            ->orWhere('q.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('q.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
