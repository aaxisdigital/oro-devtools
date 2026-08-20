<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The "one recorded run" columns shared by the tool history entities: when it ran, how it ended and
 * how long it took.
 *
 * The mapping lives on the trait, so the column definitions stay identical across entities by
 * construction — they are the same three columns (run_at, result, time_ms) each history table
 * already had, so adopting this changes no schema.
 */
trait RunHistoryFieldsTrait
{
    #[ORM\Column(name: 'run_at', type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $runAt = null;

    #[ORM\Column(name: 'result', type: Types::STRING, length: 16, nullable: true)]
    private ?string $result = null;

    #[ORM\Column(name: 'time_ms', type: Types::INTEGER, nullable: true)]
    private ?int $timeMs = null;

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
