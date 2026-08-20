<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Controller;

use Aaxis\Bundle\DevToolsBundle\Database\DatabaseInspector;
use Aaxis\Bundle\DevToolsBundle\Entity\QueryHistory;
use Aaxis\Bundle\DevToolsBundle\Entity\SavedQuery;
use Aaxis\Bundle\DevToolsBundle\Manager\QueryHistoryManager;
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
    use ResultExportEndpointTrait;
    use SavedQueryEndpointsTrait;

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
        return $this->exportResponse($request);
    }

    #[Route(path: '/database-viewer/saved-queries', name: 'aaxis_devtools_database_viewer_saved_queries', methods: ['GET'])]
    public function savedQueriesAction(): JsonResponse
    {
        return $this->listSavedQueries();
    }

    #[Route(
        path: '/database-viewer/saved-queries/all',
        name: 'aaxis_devtools_database_viewer_saved_queries_all',
        methods: ['GET']
    )]
    public function savedQueriesAllAction(): JsonResponse
    {
        return $this->listAllSavedQueries();
    }

    #[Route(
        path: '/database-viewer/saved-queries',
        name: 'aaxis_devtools_database_viewer_saved_query_create',
        methods: ['POST']
    )]
    #[CsrfProtection]
    public function createSavedQueryAction(Request $request): JsonResponse
    {
        return $this->createSavedQueryResponse($request);
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
        return $this->updateSavedQueryResponse($request, $id);
    }

    #[\Override]
    protected function exportEnabledConfigKey(): string
    {
        return 'aaxis_devtools.database_viewer_allow_export';
    }

    #[\Override]
    protected function savedQueryTool(): string
    {
        return SavedQuery::TOOL_DATABASE;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(
            parent::getSubscribedServices(),
            self::savedQuerySubscribedServices(),
            self::resultExportSubscribedServices(),
            [
                DatabaseInspector::class,
                QueryHistoryManager::class,
                ConfigManager::class,
                LoggerInterface::class,
            ]
        );
    }
}
