<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support;

use MongoDB\BSON\Int64;
use MongoDB\Driver\CursorInterface;
use MongoDB\Driver\Server;

/**
 * Array-backed MongoDB\Driver\CursorInterface: MongoDB\Collection::find() is declared to return one
 * and the driver's own Cursor is final.
 */
final class FakeMongoCursor implements CursorInterface
{
    private int $position = 0;

    /**
     * @param array<int, object|array<string, mixed>> $documents
     */
    public function __construct(private readonly array $documents)
    {
    }

    public function current(): object|array|null
    {
        return $this->documents[$this->position] ?? null;
    }

    public function getId(): Int64
    {
        return new Int64(0);
    }

    public function getServer(): Server
    {
        throw new \LogicException('Not available in the fake cursor.');
    }

    public function isDead(): bool
    {
        return $this->position >= \count($this->documents);
    }

    public function key(): ?int
    {
        return $this->position < \count($this->documents) ? $this->position : null;
    }

    public function setTypeMap(array $typemap): void
    {
    }

    public function toArray(): array
    {
        return $this->documents;
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function valid(): bool
    {
        return $this->position < \count($this->documents);
    }

    public function rewind(): void
    {
        $this->position = 0;
    }
}
