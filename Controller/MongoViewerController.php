<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Controller;

use Aaxis\Bundle\DevToolsBundle\Mongo\MongoInspector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only JSON endpoints backing the MongoDB Viewer page: server overview, collection listing
 * and document browsing. All operations are read-only.
 */
class MongoViewerController extends AbstractController
{
    #[Route(path: '/mongodb-viewer/overview', name: 'aaxis_devtools_mongodb_viewer_overview', methods: ['GET'])]
    public function overviewAction(): JsonResponse
    {
        try {
            return new JsonResponse(['success' => true] + $this->inspector()->getOverview());
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $this->inspector()->redactDsnCredentials($e->getMessage())], 502);
        }
    }

    #[Route(path: '/mongodb-viewer/collections', name: 'aaxis_devtools_mongodb_viewer_collections', methods: ['GET'])]
    public function collectionsAction(Request $request): JsonResponse
    {
        $connection = $this->connection($request);
        $db = (string) $request->query->get('db', '');
        if ($db === '') {
            return new JsonResponse(['success' => false, 'message' => 'A database is required.'], 400);
        }

        try {
            return new JsonResponse(['success' => true, 'collections' => $this->inspector()->listCollections($connection, $db)]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $this->inspector()->redactDsnCredentials($e->getMessage())], 502);
        }
    }

    #[Route(path: '/mongodb-viewer/documents', name: 'aaxis_devtools_mongodb_viewer_documents', methods: ['GET'])]
    public function documentsAction(Request $request): JsonResponse
    {
        $connection = $this->connection($request);
        $db = (string) $request->query->get('db', '');
        $collection = (string) $request->query->get('collection', '');
        if ($db === '' || $collection === '') {
            return new JsonResponse(['success' => false, 'message' => 'A database and collection are required.'], 400);
        }

        try {
            $data = $this->inspector()->findDocuments(
                $connection,
                $db,
                $collection,
                (string) $request->query->get('filter', ''),
                (int) $request->query->get('skip', 0),
                (int) $request->query->get('limit', MongoInspector::DEFAULT_LIMIT)
            );

            return new JsonResponse(['success' => true] + $data);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $this->inspector()->redactDsnCredentials($e->getMessage())], 502);
        }
    }

    private function inspector(): MongoInspector
    {
        return $this->container->get(MongoInspector::class);
    }

    /**
     * Resolves the requested connection key, defaulting to the private connection. Unknown values
     * fall back to private so a malformed request can never reach an unintended endpoint.
     */
    private function connection(Request $request): string
    {
        $connection = (string) $request->query->get('connection', MongoInspector::CONNECTION_PRIVATE);

        return $connection === MongoInspector::CONNECTION_PUBLIC
            ? MongoInspector::CONNECTION_PUBLIC
            : MongoInspector::CONNECTION_PRIVATE;
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            MongoInspector::class,
        ]);
    }
}
