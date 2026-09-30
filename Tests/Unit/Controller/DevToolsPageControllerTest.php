<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Controller;

use Aaxis\Bundle\DevToolsBundle\Controller\DevToolsPageController;
use Aaxis\Bundle\DevToolsBundle\Filesystem\FilesystemBrowser;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\ControllerTestCase;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;

class DevToolsPageControllerTest extends ControllerTestCase
{
    private ConfigManager&MockObject $config;
    private DevToolsPageController $controller;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigManager::class);
        $this->controller = new DevToolsPageController();
        $this->wire($this->controller, [
            ConfigManager::class => $this->config,
            FilesystemBrowser::class => new class('/srv/app') extends FilesystemBrowser {
                public function resolveBasePath(string $configured): string
                {
                    return 'resolved:' . $configured;
                }
            },
        ]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function configure(array $values): void
    {
        $this->config->method('get')->willReturnCallback(static fn (string $key) => $values[$key] ?? null);
    }

    public function testDatabaseViewerOptionsAreCastToBooleans(): void
    {
        $this->configure([
            'aaxis_devtools.database_viewer_show_views' => 1,
            'aaxis_devtools.database_viewer_show_functions' => '',
            'aaxis_devtools.database_viewer_show_row_numbers' => true,
            'aaxis_devtools.database_viewer_allow_export' => null,
        ]);

        self::assertSame(['options' => [
            'showViews' => true,
            'showFunctions' => false,
            'showRowNumbers' => true,
            'allowExport' => false,
        ]], $this->controller->databaseViewerAction());
    }

    public function testNetworkToolsListEachToolsToggle(): void
    {
        $this->configure([
            'aaxis_devtools.network_tools_dns' => true,
            'aaxis_devtools.network_tools_ssl_cert' => true,
            'aaxis_devtools.network_tools_curl' => false,
        ]);

        self::assertSame(['enabledTools' => [
            'dns' => true,
            'ping' => false,
            'traceroute' => false,
            'socket' => false,
            'curl' => false,
            'ssl_cert' => true,
            'ciphers' => false,
        ]], $this->controller->networkToolsAction());
    }

    public function testElasticViewerOptions(): void
    {
        $this->configure(['aaxis_devtools.elastic_viewer_allow_export' => true]);

        self::assertSame(['options' => ['allowExport' => true]], $this->controller->elasticViewerAction());
    }

    public function testRedisAndMongoPagesNeedNoParameters(): void
    {
        self::assertSame([], $this->controller->redisViewerAction());
        self::assertSame([], $this->controller->mongoViewerAction());
    }

    public function testFilesystemViewerResolvesTheBasePathAndExposesTheColumnToggles(): void
    {
        $this->configure([
            'aaxis_devtools.filesystem_viewer_base_path' => '/data',
            'aaxis_devtools.filesystem_viewer_restricted' => true,
            'aaxis_devtools.filesystem_viewer_col_type' => true,
            'aaxis_devtools.filesystem_viewer_col_created' => false,
            'aaxis_devtools.filesystem_viewer_col_modified' => true,
            'aaxis_devtools.filesystem_viewer_col_owner_user' => false,
            'aaxis_devtools.filesystem_viewer_col_owner_group' => true,
            'aaxis_devtools.filesystem_viewer_col_filename' => true,
            'aaxis_devtools.filesystem_viewer_col_filesize' => false,
            'aaxis_devtools.filesystem_viewer_col_preview' => true,
        ]);

        self::assertSame(['options' => [
            'basePath' => 'resolved:/data',
            'restricted' => true,
            'columns' => [
                'type' => true,
                'created' => false,
                'modified' => true,
                'ownerUser' => false,
                'ownerGroup' => true,
                'filename' => true,
                'filesize' => false,
                'preview' => true,
            ],
        ]], $this->controller->filesystemViewerAction());
    }

    public function testFilesystemViewerPassesAnUnsetBasePathAsAnEmptyString(): void
    {
        $this->configure([]);

        self::assertSame('resolved:', $this->controller->filesystemViewerAction()['options']['basePath']);
        self::assertFalse($this->controller->filesystemViewerAction()['options']['restricted']);
    }

    public function testBucketViewerHasFixedObjectColumnsAndTheReadOnlyToggle(): void
    {
        $this->configure(['aaxis_devtools.bucket_viewer_read_only' => 1]);

        self::assertSame(['options' => [
            'readOnly' => true,
            'columns' => [
                'type' => true,
                'created' => false,
                'modified' => true,
                'ownerUser' => false,
                'ownerGroup' => false,
                'filename' => true,
                'filesize' => true,
                'preview' => true,
            ],
        ]], $this->controller->bucketViewerAction());
    }

    public function testSubscribesToTheServicesItUses(): void
    {
        $services = DevToolsPageController::getSubscribedServices();

        self::assertContains(ConfigManager::class, $services);
        self::assertContains(FilesystemBrowser::class, $services);
    }
}
