<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Controller;

use Aaxis\Bundle\DevToolsBundle\Filesystem\FilesystemBrowser;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Provides the back-office pages for the "Aaxis Dev Tools" section.
 *
 * Each tool page receives its System Configuration options so the front-end
 * components can adapt (hide/show features) accordingly.
 */
class DevToolsPageController extends AbstractController
{
    #[Route(path: '/database-viewer', name: 'aaxis_devtools_database_viewer')]
    #[Template('@AaxisDevTools/Tools/databaseViewer.html.twig')]
    public function databaseViewerAction(): array
    {
        $config = $this->config();

        return [
            'options' => [
                'showViews' => (bool) $config->get('aaxis_devtools.database_viewer_show_views'),
                'showFunctions' => (bool) $config->get('aaxis_devtools.database_viewer_show_functions'),
                'showRowNumbers' => (bool) $config->get('aaxis_devtools.database_viewer_show_row_numbers'),
                'allowExport' => (bool) $config->get('aaxis_devtools.database_viewer_allow_export'),
            ],
        ];
    }

    #[Route(path: '/network-tools', name: 'aaxis_devtools_network_tools')]
    #[Template('@AaxisDevTools/Tools/networkTools.html.twig')]
    public function networkToolsAction(): array
    {
        $config = $this->config();
        $tools = ['dns', 'ping', 'traceroute', 'socket', 'curl', 'ssl_cert', 'ciphers'];

        $enabledTools = [];
        foreach ($tools as $tool) {
            $enabledTools[$tool] = (bool) $config->get('aaxis_devtools.network_tools_' . $tool);
        }

        return ['enabledTools' => $enabledTools];
    }

    #[Route(path: '/elastic-viewer', name: 'aaxis_devtools_elastic_viewer')]
    #[Template('@AaxisDevTools/Tools/elasticViewer.html.twig')]
    public function elasticViewerAction(): array
    {
        $config = $this->config();

        return [
            'options' => [
                'allowExport' => (bool) $config->get('aaxis_devtools.elastic_viewer_allow_export'),
            ],
        ];
    }

    #[Route(path: '/redis-viewer', name: 'aaxis_devtools_redis_viewer')]
    #[Template('@AaxisDevTools/Tools/redisViewer.html.twig')]
    public function redisViewerAction(): array
    {
        return [];
    }

    #[Route(path: '/mongodb-viewer', name: 'aaxis_devtools_mongodb_viewer')]
    #[Template('@AaxisDevTools/Tools/mongoViewer.html.twig')]
    public function mongoViewerAction(): array
    {
        return [];
    }

    #[Route(path: '/filesystem-browser', name: 'aaxis_devtools_filesystem_browser')]
    #[Template('@AaxisDevTools/Tools/filesystemBrowser.html.twig')]
    public function filesystemBrowserAction(): array
    {
        $config = $this->config();
        $browser = $this->container->get(FilesystemBrowser::class);
        $basePath = $browser->resolveBasePath((string) $config->get('aaxis_devtools.filesystem_browser_base_path'));

        return [
            'options' => [
                'basePath' => $basePath,
                'restricted' => (bool) $config->get('aaxis_devtools.filesystem_browser_restricted'),
                'columns' => [
                    'type' => (bool) $config->get('aaxis_devtools.filesystem_browser_col_type'),
                    'created' => (bool) $config->get('aaxis_devtools.filesystem_browser_col_created'),
                    'modified' => (bool) $config->get('aaxis_devtools.filesystem_browser_col_modified'),
                    'ownerUser' => (bool) $config->get('aaxis_devtools.filesystem_browser_col_owner_user'),
                    'ownerGroup' => (bool) $config->get('aaxis_devtools.filesystem_browser_col_owner_group'),
                    'filename' => (bool) $config->get('aaxis_devtools.filesystem_browser_col_filename'),
                    'filesize' => (bool) $config->get('aaxis_devtools.filesystem_browser_col_filesize'),
                    'preview' => (bool) $config->get('aaxis_devtools.filesystem_browser_col_preview'),
                ],
            ],
        ];
    }

    #[Route(path: '/bucket-browser', name: 'aaxis_devtools_bucket_browser')]
    #[Template('@AaxisDevTools/Tools/bucketBrowser.html.twig')]
    public function bucketBrowserAction(): array
    {
        $config = $this->config();

        return [
            'options' => [
                'readOnly' => (bool) $config->get('aaxis_devtools.bucket_browser_read_only'),
                // Buckets expose name/size/modified; owner/group/created don't apply to objects.
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
            ],
        ];
    }

    private function config(): ConfigManager
    {
        return $this->container->get(ConfigManager::class);
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            ConfigManager::class,
            FilesystemBrowser::class,
        ]);
    }
}
