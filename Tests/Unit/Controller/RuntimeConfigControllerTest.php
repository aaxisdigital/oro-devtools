<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Controller;

use Aaxis\Bundle\DevToolsBundle\Config\RuntimeConfigInspector;
use Aaxis\Bundle\DevToolsBundle\Controller\RuntimeConfigController;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\ControllerTestCase;

class RuntimeConfigControllerTest extends ControllerTestCase
{
    public function testPassesTheThreeInspectorSectionsToTheTemplate(): void
    {
        $env = [['key' => 'A', 'value' => '1', 'sensitive' => false]];
        $params = [['key' => 'p', 'value' => 'x', 'sensitive' => false]];
        $runtime = [['key' => 'PHP version', 'value' => '8.4', 'sensitive' => false]];
        $inspector = $this->createMock(RuntimeConfigInspector::class);
        $inspector->method('getEnvironmentVariables')->willReturn($env);
        $inspector->method('getParameters')->willReturn($params);
        $inspector->method('getRuntimeInfo')->willReturn($runtime);
        $controller = new RuntimeConfigController();
        $this->wire($controller, [RuntimeConfigInspector::class => $inspector]);

        self::assertSame(
            ['environment' => $env, 'parameters' => $params, 'runtime' => $runtime],
            $controller->indexAction()
        );
    }

    public function testSubscribesToTheInspector(): void
    {
        self::assertContains(RuntimeConfigInspector::class, RuntimeConfigController::getSubscribedServices());
    }
}
