<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class ControllerTestCase extends TestCase
{
    /**
     * Gives the controller a service container holding exactly the given services (id => instance).
     *
     * @param array<string, object> $services
     */
    protected function wire(AbstractController $controller, array $services): void
    {
        $container = new Container();
        foreach ($services as $id => $service) {
            $container->set($id, $service);
        }
        $controller->setContainer($container);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        self::assertInstanceOf(JsonResponse::class, $response);
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    protected function post(?array $payload, ?string $raw = null): Request
    {
        return new Request([], [], [], [], [], [], $raw ?? json_encode($payload));
    }

    /**
     * @param array<string, mixed> $query
     */
    protected function get(array $query = []): Request
    {
        return new Request($query);
    }
}
