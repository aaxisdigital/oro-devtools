<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Controller;

use Aaxis\Bundle\DevToolsBundle\Network\ConnectionInfoInspector;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Connection diagnostic page: shows which local interface a request arrived on, the end-user IP
 * resolved in a trusted-proxy-aware way, and all relevant proxy/forwarded headers. The trusted-proxy
 * whitelist is entered by the user and applied live via the resolve endpoint.
 */
class ConnectionInfoController extends AbstractController
{
    #[Route(path: '/connection-info', name: 'aaxis_devtools_connection_info')]
    #[Template('@AaxisDevTools/Tools/connectionInfo.html.twig')]
    public function indexAction(Request $request): array
    {
        $inspector = $this->container->get(ConnectionInfoInspector::class);

        return [
            // Initial render uses an empty trusted-proxy list (the safe default: REMOTE_ADDR wins).
            'client' => $inspector->resolveClientIp($request, []),
            'server' => $inspector->getServerInfo($request),
            'proxyHeaders' => $inspector->getProxyHeaders($request),
            'interfaces' => $inspector->getLocalInterfaces(),
        ];
    }

    #[Route(path: '/connection-info/resolve', name: 'aaxis_devtools_connection_info_resolve', methods: ['POST'])]
    #[CsrfProtection]
    public function resolveAction(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $csv = \is_array($payload) ? (string) ($payload['trustedProxies'] ?? '') : '';

        $inspector = $this->container->get(ConnectionInfoInspector::class);
        $trustedProxies = $inspector->parseTrustedProxies($csv);

        return new JsonResponse([
            'success' => true,
            'client' => $inspector->resolveClientIp($request, $trustedProxies),
            'trustedProxies' => $trustedProxies,
        ]);
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            ConnectionInfoInspector::class,
        ]);
    }
}
