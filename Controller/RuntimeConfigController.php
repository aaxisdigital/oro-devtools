<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Controller;

use Aaxis\Bundle\DevToolsBundle\Config\RuntimeConfigInspector;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only view of the environment variables, container parameters and PHP runtime settings
 * resolved for the running instance. Sensitive values are redacted by the inspector.
 */
class RuntimeConfigController extends AbstractController
{
    #[Route(path: '/runtime-config', name: 'aaxis_devtools_runtime_config')]
    #[Template('@AaxisDevTools/Tools/runtimeConfig.html.twig')]
    public function indexAction(): array
    {
        $inspector = $this->container->get(RuntimeConfigInspector::class);

        return [
            'environment' => $inspector->getEnvironmentVariables(),
            'parameters' => $inspector->getParameters(),
            'runtime' => $inspector->getRuntimeInfo(),
        ];
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            RuntimeConfigInspector::class,
        ]);
    }
}
