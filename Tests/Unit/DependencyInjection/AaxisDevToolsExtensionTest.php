<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\DependencyInjection;

use Aaxis\Bundle\DevToolsBundle\Command\CleanupHistoryCommand;
use Aaxis\Bundle\DevToolsBundle\Connection\BucketConnectionTester;
use Aaxis\Bundle\DevToolsBundle\Controller\DevToolsPageController;
use Aaxis\Bundle\DevToolsBundle\DependencyInjection\AaxisDevToolsExtension;
use Aaxis\Bundle\DevToolsBundle\Manager\SavedQueryManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class AaxisDevToolsExtensionTest extends TestCase
{
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        (new AaxisDevToolsExtension())->load([], $this->container);
    }

    public function testAliasMatchesTheSettingKeyPrefix(): void
    {
        // Symfony would derive "aaxis_dev_tools" from the class name, which would not match the
        // aaxis_devtools.* setting keys.
        self::assertSame('aaxis_devtools', (new AaxisDevToolsExtension())->getAlias());
    }

    public function testExposesTheBundleDirectoryForTheTypeScriptBuild(): void
    {
        $dir = $this->container->getParameter('aaxis_devtools.bundle_dir');

        self::assertIsString($dir);
        self::assertFileExists($dir . '/Resources/js-src/tsconfig.json');
    }

    public function testPrependsTheDefaultSettingsUnderTheBundleAlias(): void
    {
        $prepended = $this->container->getExtensionConfig('aaxis_devtools');

        self::assertNotEmpty($prepended);
        $settings = $prepended[0]['settings'];
        self::assertTrue($settings['database_viewer_enabled']['value']);
        self::assertSame('http://minio:9000', $settings['bucket_viewer_url']['value']);
    }

    /**
     * @dataProvider serviceProvider
     */
    public function testRegistersTheServices(string $id): void
    {
        self::assertTrue($this->container->hasDefinition($id), $id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function serviceProvider(): iterable
    {
        yield 'cleanup command' => [CleanupHistoryCommand::class];
        yield 'bucket tester' => [BucketConnectionTester::class];
        yield 'saved query manager' => [SavedQueryManager::class];
        yield 'page controller' => [DevToolsPageController::class];
    }
}
