<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Controller;

use Aaxis\Bundle\DevToolsBundle\Redis\RedisInspector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only JSON endpoints backing the Redis Viewer page: server overview, key scanning and
 * value inspection. All operations are read-only.
 */
class RedisViewerController extends AbstractController
{
    #[Route(path: '/redis-viewer/overview', name: 'aaxis_devtools_redis_viewer_overview', methods: ['GET'])]
    public function overviewAction(): JsonResponse
    {
        try {
            return new JsonResponse(['success' => true] + $this->inspector()->getOverview());
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 502);
        }
    }

    #[Route(path: '/redis-viewer/keys', name: 'aaxis_devtools_redis_viewer_keys', methods: ['GET'])]
    public function keysAction(Request $request): JsonResponse
    {
        try {
            $data = $this->inspector()->scanKeys(
                (int) $request->query->get('db', 0),
                (string) $request->query->get('pattern', ''),
                (string) $request->query->get('cursor', ''),
                (int) $request->query->get('count', 200)
            );

            return new JsonResponse(['success' => true] + $data);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 502);
        }
    }

    #[Route(path: '/redis-viewer/value', name: 'aaxis_devtools_redis_viewer_value', methods: ['GET'])]
    public function valueAction(Request $request): JsonResponse
    {
        $key = (string) $request->query->get('key', '');
        if ($key === '') {
            return new JsonResponse(['success' => false, 'message' => 'A key is required.'], 400);
        }

        try {
            $data = $this->inspector()->getValue((int) $request->query->get('db', 0), $key);

            return new JsonResponse(['success' => true] + $data);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 502);
        }
    }

    private function inspector(): RedisInspector
    {
        return $this->container->get(RedisInspector::class);
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            RedisInspector::class,
        ]);
    }
}
