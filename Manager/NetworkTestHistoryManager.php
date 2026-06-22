<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Manager;

use Aaxis\Bundle\DevToolsBundle\Entity\NetworkTestHistory;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Creates and finalizes {@see NetworkTestHistory} records around a network test run.
 */
class NetworkTestHistoryManager
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TokenAccessorInterface $tokenAccessor,
    ) {
    }

    /**
     * Records a new test attempt with its initial information and returns the persisted record.
     *
     * @param array<string, mixed> $payload
     */
    public function start(string $test, array $payload): NetworkTestHistory
    {
        $record = new NetworkTestHistory();
        $record->setTest($test);
        $record->setPayload($payload);
        $record->setRunAt(new \DateTime('now', new \DateTimeZone('UTC')));

        $user = $this->tokenAccessor->getUser();
        if ($user instanceof User) {
            $record->setUser($user);
        }

        $em = $this->doctrine->getManagerForClass(NetworkTestHistory::class);
        $em->persist($record);
        $em->flush();

        return $record;
    }

    /**
     * Finalizes the record with the test outcome.
     */
    public function finish(NetworkTestHistory $record, string $result, int $timeMs, ?string $response): void
    {
        $record->setResult($result);
        $record->setTimeMs($timeMs);
        $record->setResponse($response);

        $this->doctrine->getManagerForClass(NetworkTestHistory::class)->flush();
    }
}
