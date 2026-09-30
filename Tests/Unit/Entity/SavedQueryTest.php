<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Entity;

use Aaxis\Bundle\DevToolsBundle\Entity\SavedQuery;
use Oro\Bundle\UserBundle\Entity\User;
use PHPUnit\Framework\TestCase;

class SavedQueryTest extends TestCase
{
    public function testDefaults(): void
    {
        $query = new SavedQuery();

        self::assertNull($query->getId());
        self::assertNull($query->getUser());
        self::assertSame(SavedQuery::TOOL_DATABASE, $query->getTool());
        self::assertFalse($query->isPublic());
        self::assertNull($query->getQueryName());
        self::assertNull($query->getQueryText());
        self::assertNull($query->getCreatedAt());
        self::assertNull($query->getUpdatedAt());
    }

    public function testFluentSetters(): void
    {
        $user = new User();
        $created = new \DateTime('2026-03-01 10:00:00');
        $updated = new \DateTimeImmutable('2026-03-02 10:00:00');

        $query = (new SavedQuery())
            ->setUser($user)
            ->setTool(SavedQuery::TOOL_ELASTIC)
            ->setPublic(true)
            ->setQueryName('Top orders')
            ->setQueryText('FROM orders | LIMIT 5')
            ->setCreatedAt($created)
            ->setUpdatedAt($updated);

        self::assertSame($user, $query->getUser());
        self::assertSame('elastic', $query->getTool());
        self::assertTrue($query->isPublic());
        self::assertSame('Top orders', $query->getQueryName());
        self::assertSame('FROM orders | LIMIT 5', $query->getQueryText());
        self::assertSame($created, $query->getCreatedAt());
        self::assertSame($updated, $query->getUpdatedAt());
    }

    public function testTheOwnerCanBeCleared(): void
    {
        $query = (new SavedQuery())->setUser(new User())->setUser(null);

        self::assertNull($query->getUser());
    }
}
