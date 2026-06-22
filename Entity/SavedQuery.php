<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Entity;

use Aaxis\Bundle\DevToolsBundle\Entity\Repository\SavedQueryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * A query saved (as favorite) from the Database Viewer, either private to its owner or public.
 */
#[ORM\Entity(repositoryClass: SavedQueryRepository::class)]
#[ORM\Table(name: 'aaxis_dbviewer_saved_query')]
#[ORM\Index(columns: ['is_public', 'created_at'], name: 'aaxis_saved_query_public_idx')]
class SavedQuery
{
    public const int NAME_MAX_LENGTH = 40;

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(name: 'is_public', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $public = false;

    #[ORM\Column(name: 'query_name', type: Types::STRING, length: self::NAME_MAX_LENGTH)]
    private ?string $queryName = null;

    #[ORM\Column(name: 'query_text', type: Types::TEXT)]
    private ?string $queryText = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

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

    public function isPublic(): bool
    {
        return $this->public;
    }

    public function setPublic(bool $public): self
    {
        $this->public = $public;

        return $this;
    }

    public function getQueryName(): ?string
    {
        return $this->queryName;
    }

    public function setQueryName(string $queryName): self
    {
        $this->queryName = $queryName;

        return $this;
    }

    public function getQueryText(): ?string
    {
        return $this->queryText;
    }

    public function setQueryText(string $queryText): self
    {
        $this->queryText = $queryText;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
