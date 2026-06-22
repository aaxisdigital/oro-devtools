<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Manager;

use Aaxis\Bundle\DevToolsBundle\Entity\QueryHistory;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Creates and finalizes {@see QueryHistory} records around a query execution.
 */
class QueryHistoryManager
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TokenAccessorInterface $tokenAccessor,
    ) {
    }

    /**
     * Records a new execution attempt and returns the persisted record.
     */
    public function start(string $query): QueryHistory
    {
        $record = new QueryHistory();
        $record->setQuery($query);
        $record->setRunAt(new \DateTime('now', new \DateTimeZone('UTC')));

        $user = $this->tokenAccessor->getUser();
        if ($user instanceof User) {
            $record->setUser($user);
        }

        $em = $this->doctrine->getManagerForClass(QueryHistory::class);
        $em->persist($record);
        $em->flush();

        return $record;
    }

    /**
     * Finalizes the record with the execution outcome.
     */
    public function finish(QueryHistory $record, string $result, int $timeMs, ?int $recordCount): void
    {
        $record->setResult($result);
        $record->setTimeMs($timeMs);
        $record->setRecordCount($recordCount);

        $this->doctrine->getManagerForClass(QueryHistory::class)->flush();
    }
}
