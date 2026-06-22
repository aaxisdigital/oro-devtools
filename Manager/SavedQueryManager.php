<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Manager;

use Aaxis\Bundle\DevToolsBundle\Entity\Repository\SavedQueryRepository;
use Aaxis\Bundle\DevToolsBundle\Entity\SavedQuery;
use Aaxis\Bundle\DevToolsBundle\Exception\DuplicateQueryNameException;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Create, update and list saved queries (favorites) for the Database Viewer.
 */
class SavedQueryManager
{
    /** Number of recent items of each visibility shown in the "Queries" menu. */
    public const int MENU_LIMIT = 10;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TokenAccessorInterface $tokenAccessor,
    ) {
    }

    public function create(string $name, string $queryText, bool $public): SavedQuery
    {
        $name = $this->normalizeName($name);
        $user = $this->tokenAccessor->getUser();
        $userId = $user instanceof User ? $user->getId() : null;

        $this->assertUniqueName($name, $public, $userId, null);

        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        $entity = new SavedQuery();
        $entity->setQueryName($name);
        $entity->setQueryText($queryText);
        $entity->setPublic($public);
        $entity->setCreatedAt($now);
        $entity->setUpdatedAt($now);
        if ($user instanceof User) {
            $entity->setUser($user);
        }

        $em = $this->doctrine->getManagerForClass(SavedQuery::class);
        $em->persist($entity);
        $em->flush();

        return $entity;
    }

    /**
     * Updates any subset of name / visibility / text. Pass null to keep the current value.
     */
    public function update(SavedQuery $entity, ?string $name, ?bool $public, ?string $text): SavedQuery
    {
        $finalName = null !== $name ? $this->normalizeName($name) : (string) $entity->getQueryName();
        $finalPublic = $public ?? $entity->isPublic();
        $ownerId = null !== $entity->getUser() ? $entity->getUser()->getId() : null;

        $this->assertUniqueName($finalName, $finalPublic, $ownerId, $entity->getId());

        $entity->setQueryName($finalName);
        $entity->setPublic($finalPublic);
        if (null !== $text) {
            $entity->setQueryText($text);
        }
        $entity->setUpdatedAt(new \DateTime('now', new \DateTimeZone('UTC')));

        $this->doctrine->getManagerForClass(SavedQuery::class)->flush();

        return $entity;
    }

    /**
     * @throws DuplicateQueryNameException when the name collides within its visibility scope
     */
    private function assertUniqueName(string $name, bool $public, ?int $userId, ?int $excludeId): void
    {
        $repo = $this->getRepository();
        if ($public) {
            if ($repo->existsPublicWithName($name, $excludeId)) {
                throw new DuplicateQueryNameException(
                    sprintf('A public query named "%s" already exists.', $name)
                );
            }
        } elseif (null !== $userId && $repo->existsPrivateWithName($userId, $name, $excludeId)) {
            throw new DuplicateQueryNameException(
                sprintf('You already have a private query named "%s".', $name)
            );
        }
    }

    /**
     * @return array{
     *     public: SavedQuery[],
     *     private: SavedQuery[],
     *     hasMorePublic: bool,
     *     hasMorePrivate: bool
     * }
     */
    public function getMenuData(int $userId): array
    {
        $repo = $this->getRepository();

        return [
            'public' => $repo->findRecentPublic(self::MENU_LIMIT),
            'private' => $repo->findRecentPrivateForUser($userId, self::MENU_LIMIT),
            'hasMorePublic' => $repo->countPublic() > self::MENU_LIMIT,
            'hasMorePrivate' => $repo->countPrivateForUser($userId) > self::MENU_LIMIT,
        ];
    }

    public function getRepository(): SavedQueryRepository
    {
        return $this->doctrine->getRepository(SavedQuery::class);
    }

    public function getCurrentUserId(): ?int
    {
        $user = $this->tokenAccessor->getUser();

        return $user instanceof User ? $user->getId() : null;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'Untitled query';
        }

        return mb_substr($name, 0, SavedQuery::NAME_MAX_LENGTH);
    }
}
