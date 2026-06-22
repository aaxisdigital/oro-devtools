<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Controller;

use Aaxis\Bundle\DevToolsBundle\Entity\NetworkTestHistory;
use Aaxis\Bundle\DevToolsBundle\Manager\NetworkTestHistoryManager;
use Aaxis\Bundle\DevToolsBundle\Network\NetworkToolExecutor;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Runs the network diagnostic tools and returns console-style text output.
 */
class NetworkToolsController extends AbstractController
{
    #[Route(path: '/network-tools/run', name: 'aaxis_devtools_network_tools_run', methods: ['POST'])]
    #[CsrfProtection]
    public function runAction(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'output' => 'Invalid payload.'], 400);
        }

        $tool = (string) ($payload['tool'] ?? '');
        if (!\in_array($tool, NetworkToolExecutor::TOOLS, true)) {
            return new JsonResponse(['success' => false, 'output' => 'Unknown tool.'], 400);
        }

        // Respect the per-test System Configuration toggle (e.g. "ssl-cert" -> network_tools_ssl_cert).
        $toggleKey = 'aaxis_devtools.network_tools_' . str_replace('-', '_', $tool);
        if (!$this->container->get(ConfigManager::class)->get($toggleKey)) {
            return new JsonResponse(['success' => false, 'output' => 'This tool is disabled.'], 403);
        }

        $params = [
            'host' => $payload['host'] ?? '',
            'port' => $payload['port'] ?? null,
            'path' => $payload['path'] ?? '',
            'timeout' => $payload['timeout'] ?? null,
            'mode' => $payload['mode'] ?? null,
        ];

        $historyManager = $this->container->get(NetworkTestHistoryManager::class);
        $record = $historyManager->start(
            NetworkToolExecutor::TOOL_LABELS[$tool] ?? $tool,
            array_filter($params, static fn ($value) => $value !== null && $value !== '')
        );

        $startedAt = microtime(true);

        try {
            $output = $this->container->get(NetworkToolExecutor::class)->run($tool, $params);
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

            $historyManager->finish($record, NetworkTestHistory::RESULT_SUCCESS, $elapsedMs, $output);

            return new JsonResponse(['success' => true, 'output' => $output]);
        } catch (\InvalidArgumentException $e) {
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            $historyManager->finish($record, NetworkTestHistory::RESULT_ERROR, $elapsedMs, $e->getMessage());

            return new JsonResponse(['success' => false, 'output' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            $historyManager->finish($record, NetworkTestHistory::RESULT_ERROR, $elapsedMs, 'Error: ' . $e->getMessage());

            $this->container->get(LoggerInterface::class)
                ->warning('Aaxis Tools network tool failed.', ['exception' => $e, 'tool' => $tool]);

            return new JsonResponse(['success' => false, 'output' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            NetworkToolExecutor::class,
            NetworkTestHistoryManager::class,
            ConfigManager::class,
            LoggerInterface::class,
        ]);
    }
}
