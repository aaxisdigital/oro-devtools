<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Controller;

use Aaxis\Bundle\DevToolsBundle\Database\ResultExporter;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared "export the current result set" endpoint for the Database Viewer and the Elastic Viewer.
 *
 * Same contract as {@see SavedQueryEndpointsTrait}: each controller keeps its own thin routed action
 * (the paths and route names must stay distinct per tool) and only supplies its feature-toggle key
 * via {@see exportEnabledConfigKey()}.
 */
trait ResultExportEndpointTrait
{
    /** Config key of this tool's "allow export" toggle. */
    abstract protected function exportEnabledConfigKey(): string;

    protected function exportResponse(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid payload.'], 400);
        }

        $format = (string) ($payload['format'] ?? '');
        $columns = array_values(array_map('strval', (array) ($payload['columns'] ?? [])));
        $rows = array_values((array) ($payload['rows'] ?? []));

        if (!$this->container->get(ConfigManager::class)->get($this->exportEnabledConfigKey())) {
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
     * @return array<int, class-string>
     */
    protected static function resultExportSubscribedServices(): array
    {
        return [ResultExporter::class, ConfigManager::class];
    }
}
