<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Controller;

use Aaxis\Bundle\DevToolsBundle\Entity\SavedQuery;
use Aaxis\Bundle\DevToolsBundle\Exception\DuplicateQueryNameException;
use Aaxis\Bundle\DevToolsBundle\Manager\SavedQueryManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared favourites (saved queries) endpoints for the Database Viewer and the Elastic Viewer.
 *
 * The two viewers share one table; each controller only supplies its tool via {@see savedQueryTool()}
 * and declares the thin routed actions that delegate here. Cross-tool access is blocked: an update
 * against a query belonging to a different tool 404s.
 */
trait SavedQueryEndpointsTrait
{
    /** @return SavedQuery::TOOL_* */
    abstract protected function savedQueryTool(): string;

    protected function listSavedQueries(): JsonResponse
    {
        $manager = $this->container->get(SavedQueryManager::class);
        $userId = (int) $manager->getCurrentUserId();
        $data = $manager->getMenuData($userId, $this->savedQueryTool());

        return new JsonResponse([
            'public' => array_map(fn (SavedQuery $q) => $this->serializeSavedQuery($q, $userId), $data['public']),
            'private' => array_map(fn (SavedQuery $q) => $this->serializeSavedQuery($q, $userId), $data['private']),
            'hasMorePublic' => $data['hasMorePublic'],
            'hasMorePrivate' => $data['hasMorePrivate'],
        ]);
    }

    protected function listAllSavedQueries(): JsonResponse
    {
        $manager = $this->container->get(SavedQueryManager::class);
        $userId = (int) $manager->getCurrentUserId();
        $items = $manager->getRepository()->findAllVisibleForUser($this->savedQueryTool(), $userId);

        return new JsonResponse([
            'items' => array_map(fn (SavedQuery $q) => $this->serializeSavedQuery($q, $userId), $items),
        ]);
    }

    protected function createSavedQueryResponse(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid payload.'], 400);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        $text = (string) ($payload['text'] ?? '');
        $public = (bool) ($payload['public'] ?? false);

        if ($name === '' || trim($text) === '') {
            return new JsonResponse(['success' => false, 'message' => 'Name and query text are required.'], 400);
        }

        $manager = $this->container->get(SavedQueryManager::class);
        try {
            $entity = $manager->create($name, $text, $public, $this->savedQueryTool());
            $response = new JsonResponse([
                'success' => true,
                'query' => $this->serializeSavedQuery($entity, (int) $manager->getCurrentUserId()),
            ]);
        } catch (DuplicateQueryNameException $e) {
            $response = new JsonResponse(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return $response;
    }

    protected function updateSavedQueryResponse(Request $request, int $id): JsonResponse
    {
        $manager = $this->container->get(SavedQueryManager::class);
        $userId = (int) $manager->getCurrentUserId();

        /** @var SavedQuery|null $entity */
        $entity = $manager->getRepository()->find($id);
        $denied = $this->denyUpdate($entity, $userId);
        if ($denied !== null) {
            return $denied;
        }

        $payload = json_decode($request->getContent(), true);
        $rejection = $this->rejectUpdatePayload($payload);
        if ($rejection !== null) {
            return $rejection;
        }

        $name = \array_key_exists('name', $payload) ? trim((string) $payload['name']) : null;
        $public = \array_key_exists('public', $payload) ? (bool) $payload['public'] : null;
        $text = \array_key_exists('text', $payload) ? (string) $payload['text'] : null;

        try {
            $manager->update($entity, $name, $public, $text);
            $response = new JsonResponse(['success' => true, 'query' => $this->serializeSavedQuery($entity, $userId)]);
        } catch (DuplicateQueryNameException $e) {
            $response = new JsonResponse(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return $response;
    }

    /**
     * The 404/403 response when the query cannot be updated by this user; null when it can.
     */
    private function denyUpdate(?SavedQuery $entity, int $userId): ?JsonResponse
    {
        // Scope by tool too, so one viewer cannot read/modify the other's saved queries by id.
        if (null === $entity || $entity->getTool() !== $this->savedQueryTool()) {
            return new JsonResponse(['success' => false, 'message' => 'Saved query not found.'], 404);
        }
        if (null === $entity->getUser() || $entity->getUser()->getId() !== $userId) {
            return new JsonResponse(['success' => false, 'message' => 'You can only update your own queries.'], 403);
        }

        return null;
    }

    /**
     * The 400 response for a malformed body or a blank name/text; null when the payload is acceptable.
     */
    private function rejectUpdatePayload(mixed $payload): ?JsonResponse
    {
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid payload.'], 400);
        }

        return match (true) {
            \array_key_exists('name', $payload) && trim((string) $payload['name']) === ''
                => new JsonResponse(['success' => false, 'message' => 'Query name is required.'], 400),
            \array_key_exists('text', $payload) && trim((string) $payload['text']) === ''
                => new JsonResponse(['success' => false, 'message' => 'Query text is required.'], 400),
            default => null,
        };
    }

    /**
     * @return array{id: int|null, name: string|null, public: bool, text: string|null, owned: bool}
     */
    private function serializeSavedQuery(SavedQuery $query, int $currentUserId): array
    {
        return [
            'id' => $query->getId(),
            'name' => $query->getQueryName(),
            'public' => $query->isPublic(),
            'text' => $query->getQueryText(),
            'owned' => null !== $query->getUser() && $query->getUser()->getId() === $currentUserId,
        ];
    }

    /**
     * @return array<int, class-string>
     */
    protected static function savedQuerySubscribedServices(): array
    {
        return [SavedQueryManager::class];
    }
}
