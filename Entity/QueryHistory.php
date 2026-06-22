<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Entity;

use Aaxis\Bundle\DevToolsBundle\Entity\Repository\QueryHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Records each query execution triggered from the Database Viewer.
 */
#[ORM\Entity(repositoryClass: QueryHistoryRepository::class)]
#[ORM\Table(name: 'aaxis_dbviewer_query_history')]
#[ORM\Index(columns: ['run_at'], name: 'aaxis_dbviewer_history_run_at_idx')]
class QueryHistory
{
    public const string RESULT_SUCCESS = 'success';
    public const string RESULT_ERROR = 'error';
    public const string RESULT_BLOCKED = 'blocked';

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(name: 'query', type: Types::TEXT)]
    private ?string $query = null;

    #[ORM\Column(name: 'run_at', type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $runAt = null;

    #[ORM\Column(name: 'result', type: Types::STRING, length: 16, nullable: true)]
    private ?string $result = null;

    #[ORM\Column(name: 'time_ms', type: Types::INTEGER, nullable: true)]
    private ?int $timeMs = null;

    #[ORM\Column(name: 'record_count', type: Types::INTEGER, nullable: true)]
    private ?int $recordCount = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getQuery(): ?string
    {
        return $this->query;
    }

    public function setQuery(string $query): self
    {
        $this->query = $query;

        return $this;
    }

    public function getRunAt(): ?\DateTimeInterface
    {
        return $this->runAt;
    }

    public function setRunAt(\DateTimeInterface $runAt): self
    {
        $this->runAt = $runAt;

        return $this;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function setResult(?string $result): self
    {
        $this->result = $result;

        return $this;
    }

    public function getTimeMs(): ?int
    {
        return $this->timeMs;
    }

    public function setTimeMs(?int $timeMs): self
    {
        $this->timeMs = $timeMs;

        return $this;
    }

    public function getRecordCount(): ?int
    {
        return $this->recordCount;
    }

    public function setRecordCount(?int $recordCount): self
    {
        $this->recordCount = $recordCount;

        return $this;
    }
}
