<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Entity;

use Aaxis\Bundle\DevToolsBundle\Entity\Repository\NetworkTestHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Records each network test triggered from the Network Tools console.
 */
#[ORM\Entity(repositoryClass: NetworkTestHistoryRepository::class)]
#[ORM\Table(name: 'aaxis_network_test_history')]
#[ORM\Index(columns: ['run_at'], name: 'aaxis_net_history_run_at_idx')]
class NetworkTestHistory
{
    public const string RESULT_SUCCESS = 'success';
    public const string RESULT_ERROR = 'error';

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(name: 'test', type: Types::STRING, length: 64)]
    private ?string $test = null;

    #[ORM\Column(name: 'payload', type: Types::JSON, nullable: true, columnDefinition: 'JSONB DEFAULT NULL')]
    private ?array $payload = null;

    #[ORM\Column(name: 'response', type: Types::TEXT, nullable: true)]
    private ?string $response = null;

    #[ORM\Column(name: 'run_at', type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $runAt = null;

    #[ORM\Column(name: 'result', type: Types::STRING, length: 16, nullable: true)]
    private ?string $result = null;

    #[ORM\Column(name: 'time_ms', type: Types::INTEGER, nullable: true)]
    private ?int $timeMs = null;

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

    public function getTest(): ?string
    {
        return $this->test;
    }

    public function setTest(string $test): self
    {
        $this->test = $test;

        return $this;
    }

    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function setPayload(?array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function getResponse(): ?string
    {
        return $this->response;
    }

    public function setResponse(?string $response): self
    {
        $this->response = $response;

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
}
