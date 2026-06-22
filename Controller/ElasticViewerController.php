<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Controller;

use Aaxis\Bundle\DevToolsBundle\Database\ResultExporter;
use Aaxis\Bundle\DevToolsBundle\Elastic\ElasticInspector;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON endpoints backing the Elastic Viewer page.
 *
 * Exposes read-only access only: listing indices and running a single ES|QL query
 * (see {@see ElasticInspector}).
 */
class ElasticViewerController extends AbstractController
{
    #[Route(path: '/elastic-viewer/indices', name: 'aaxis_devtools_elastic_viewer_indices', methods: ['GET'])]
    public function indicesAction(): JsonResponse
    {
        try {
            return new JsonResponse([
                'indices' => $this->container->get(ElasticInspector::class)->getIndices(),
            ]);
        } catch (\Throwable $e) {
            $this->container->get(LoggerInterface::class)
                ->warning('Aaxis Tools elastic viewer: unable to list indices.', ['exception' => $e]);

            return new JsonResponse(['indices' => [], 'message' => $e->getMessage()], 502);
        }
    }

    #[Route(path: '/elastic-viewer/query', name: 'aaxis_devtools_elastic_viewer_query', methods: ['POST'])]
    #[CsrfProtection]
    public function queryAction(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $query = \is_array($payload) ? trim((string) ($payload['query'] ?? '')) : '';

        if ($query === '') {
            return new JsonResponse(['success' => false, 'message' => 'The query must not be empty.'], 400);
        }

        try {
            $result = $this->container->get(ElasticInspector::class)->runEsql($query);

            return new JsonResponse(['success' => true] + $result);
        } catch (\Throwable $e) {
            $this->container->get(LoggerInterface::class)
                ->info('Aaxis Tools elastic viewer query failed.', ['exception' => $e]);

            return new JsonResponse(['success' => false, 'message' => $this->cleanMessage($e->getMessage())], 422);
        }
    }

    #[Route(path: '/elastic-viewer/export', name: 'aaxis_devtools_elastic_viewer_export', methods: ['POST'])]
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

        if (!$this->container->get(ConfigManager::class)->get('aaxis_devtools.elastic_viewer_allow_export')) {
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

    /**
     * Elasticsearch error bodies are often a verbose JSON blob; surface the useful reason if present.
     */
    private function cleanMessage(string $message): string
    {
        $decoded = json_decode($message, true);
        if (\is_array($decoded)) {
            $reason = $decoded['error']['root_cause'][0]['reason']
                ?? $decoded['error']['reason']
                ?? null;
            if (\is_string($reason) && $reason !== '') {
                return $reason;
            }
        }

        return $message;
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            ElasticInspector::class,
            ResultExporter::class,
            ConfigManager::class,
            LoggerInterface::class,
        ]);
    }
}
