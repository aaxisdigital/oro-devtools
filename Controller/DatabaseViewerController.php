<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Controller;

use Aaxis\Bundle\DevToolsBundle\Database\DatabaseInspector;
use Aaxis\Bundle\DevToolsBundle\Database\ResultExporter;
use Aaxis\Bundle\DevToolsBundle\Entity\QueryHistory;
use Aaxis\Bundle\DevToolsBundle\Entity\SavedQuery;
use Aaxis\Bundle\DevToolsBundle\Exception\DuplicateQueryNameException;
use Aaxis\Bundle\DevToolsBundle\Manager\QueryHistoryManager;
use Aaxis\Bundle\DevToolsBundle\Manager\SavedQueryManager;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON endpoints backing the Database Viewer page.
 *
 * The endpoints intentionally expose only read access: table listing and the
 * execution of a single read-only statement (see {@see DatabaseInspector}).
 */
class DatabaseViewerController extends AbstractController
{
    #[Route(path: '/database-viewer/objects', name: 'aaxis_devtools_database_viewer_objects', methods: ['GET'])]
    public function objectsAction(Request $request): JsonResponse
    {
        $config = $this->container->get(ConfigManager::class);

        // The badge metric is chosen via the sidebar order button (none/quantity/bytes), so the
        // potentially expensive row-count / size queries only run when the user asks for them.
        $metric = (string) $request->query->get('metric', 'none');
        if (!\in_array($metric, ['none', 'quantity', 'bytes'], true)) {
            $metric = 'none';
        }

        return new JsonResponse([
            'objects' => $this->container->get(DatabaseInspector::class)->getDatabaseObjects(
                (bool) $config->get('aaxis_devtools.database_viewer_show_views'),
                (bool) $config->get('aaxis_devtools.database_viewer_show_functions'),
                $metric
            ),
        ]);
    }

    #[Route(path: '/database-viewer/columns', name: 'aaxis_devtools_database_viewer_columns', methods: ['GET'])]
    public function columnsAction(Request $request): JsonResponse
    {
        $name = trim((string) $request->query->get('name', ''));
        if ($name === '') {
            return new JsonResponse(['success' => false, 'message' => 'A table name is required.'], 400);
        }

        return new JsonResponse([
            'success' => true,
            'columns' => $this->container->get(DatabaseInspector::class)->getTableColumns($name),
        ]);
    }

    #[Route(path: '/database-viewer/query', name: 'aaxis_devtools_database_viewer_query', methods: ['POST'])]
    #[CsrfProtection]
    public function queryAction(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $sql = \is_array($payload) ? trim((string) ($payload['query'] ?? '')) : '';

        if ($sql === '') {
            return new JsonResponse(['success' => false, 'message' => 'The query must not be empty.'], 400);
        }

        $historyManager = $this->container->get(QueryHistoryManager::class);
        $history = $historyManager->start($sql);

        $startedAt = microtime(true);
        try {
            $result = $this->container->get(DatabaseInspector::class)->executeReadOnlyQuery($sql);
            $historyManager->finish(
                $history,
                QueryHistory::RESULT_SUCCESS,
                $this->elapsedMs($startedAt),
                $result['rowCount']
            );

            return new JsonResponse(['success' => true] + $result);
        } catch (\Throwable $e) {
            $blocked = stripos($e->getMessage(), 'read-only transaction') !== false;
            $historyManager->finish(
                $history,
                $blocked ? QueryHistory::RESULT_BLOCKED : QueryHistory::RESULT_ERROR,
                $this->elapsedMs($startedAt),
                null
            );

            $this->container->get(LoggerInterface::class)
                ->info('Aaxis Tools database viewer query failed.', ['exception' => $e]);

            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    #[Route(path: '/database-viewer/export', name: 'aaxis_devtools_database_viewer_export', methods: ['POST'])]
    #[CsrfProtection]
    public function exportAction(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid payload.'], 400);
        }

        $format = (string) ($payload['format'] ?? '');
        $columns = array_values(array_map('strval', (array) ($payload['columns'] ?? [])));
        $rows = array_values((array) ($payload['rows'] ?? []));

        if (!$this->container->get(ConfigManager::class)->get('aaxis_devtools.database_viewer_allow_export')) {
            return new JsonResponse(['success' => false, 'message' => 'Export is disabled.'], 403);
        }

        if ($columns === []) {
            return new JsonResponse(['success' => false, 'message' => 'Nothing to export.'], 400);
        }

        try {
            return $this->container->get(ResultExporter::class)->export($format, $columns, $rows);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    #[Route(path: '/database-viewer/saved-queries', name: 'aaxis_devtools_database_viewer_saved_queries', methods: ['GET'])]
    public function savedQueriesAction(): JsonResponse
    {
        $manager = $this->container->get(SavedQueryManager::class);
        $userId = (int) $manager->getCurrentUserId();
        $data = $manager->getMenuData($userId);

        return new JsonResponse([
            'public' => array_map(fn (SavedQuery $q) => $this->serializeQuery($q, $userId), $data['public']),
            'private' => array_map(fn (SavedQuery $q) => $this->serializeQuery($q, $userId), $data['private']),
            'hasMorePublic' => $data['hasMorePublic'],
            'hasMorePrivate' => $data['hasMorePrivate'],
        ]);
    }

    #[Route(
        path: '/database-viewer/saved-queries/all',
        name: 'aaxis_devtools_database_viewer_saved_queries_all',
        methods: ['GET']
    )]
    public function savedQueriesAllAction(): JsonResponse
    {
        $manager = $this->container->get(SavedQueryManager::class);
        $userId = (int) $manager->getCurrentUserId();
        $items = $manager->getRepository()->findAllVisibleForUser($userId);

        return new JsonResponse([
            'items' => array_map(fn (SavedQuery $q) => $this->serializeQuery($q, $userId), $items),
        ]);
    }

    #[Route(
        path: '/database-viewer/saved-queries',
        name: 'aaxis_devtools_database_viewer_saved_query_create',
        methods: ['POST']
    )]
    #[CsrfProtection]
    public function createSavedQueryAction(Request $request): JsonResponse
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
            $entity = $manager->create($name, $text, $public);
        } catch (DuplicateQueryNameException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return new JsonResponse([
            'success' => true,
            'query' => $this->serializeQuery($entity, (int) $manager->getCurrentUserId()),
        ]);
    }

    #[Route(
        path: '/database-viewer/saved-queries/{id}',
        name: 'aaxis_devtools_database_viewer_saved_query_update',
        requirements: ['id' => '\d+'],
        methods: ['PUT', 'POST']
    )]
    #[CsrfProtection]
    public function updateSavedQueryAction(Request $request, int $id): JsonResponse
    {
        $manager = $this->container->get(SavedQueryManager::class);
        $userId = (int) $manager->getCurrentUserId();

        /** @var SavedQuery|null $entity */
        $entity = $manager->getRepository()->find($id);
        if (null === $entity) {
            return new JsonResponse(['success' => false, 'message' => 'Saved query not found.'], 404);
        }
        if (null === $entity->getUser() || $entity->getUser()->getId() !== $userId) {
            return new JsonResponse(['success' => false, 'message' => 'You can only update your own queries.'], 403);
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid payload.'], 400);
        }

        $name = \array_key_exists('name', $payload) ? trim((string) $payload['name']) : null;
        $public = \array_key_exists('public', $payload) ? (bool) $payload['public'] : null;
        $text = \array_key_exists('text', $payload) ? (string) $payload['text'] : null;

        if (null !== $name && $name === '') {
            return new JsonResponse(['success' => false, 'message' => 'Query name is required.'], 400);
        }
        if (null !== $text && trim($text) === '') {
            return new JsonResponse(['success' => false, 'message' => 'Query text is required.'], 400);
        }

        try {
            $manager->update($entity, $name, $public, $text);
        } catch (DuplicateQueryNameException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return new JsonResponse(['success' => true, 'query' => $this->serializeQuery($entity, $userId)]);
    }

    /**
     * @return array{id: int|null, name: string|null, public: bool, text: string|null, owned: bool}
     */
    private function serializeQuery(SavedQuery $query, int $currentUserId): array
    {
        return [
            'id' => $query->getId(),
            'name' => $query->getQueryName(),
            'public' => $query->isPublic(),
            'text' => $query->getQueryText(),
            'owned' => null !== $query->getUser() && $query->getUser()->getId() === $currentUserId,
        ];
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            DatabaseInspector::class,
            ResultExporter::class,
            QueryHistoryManager::class,
            SavedQueryManager::class,
            ConfigManager::class,
            LoggerInterface::class,
        ]);
    }
}
