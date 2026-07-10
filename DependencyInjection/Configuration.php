<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\DependencyInjection;

use Oro\Bundle\ConfigBundle\DependencyInjection\SettingsBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Defines the configuration tree for the bundle ("aaxis_devtools"),
 * including the System Configuration settings for each dev tool.
 */
class Configuration implements ConfigurationInterface
{
    #[\Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('aaxis_devtools');
        $rootNode = $treeBuilder->getRootNode();

        SettingsBuilder::append($rootNode, [
            // Database Viewer
            'database_viewer_enabled' => ['type' => 'boolean', 'value' => true],
            'database_viewer_test' => ['type' => 'string', 'value' => ''],
            'database_viewer_show_views' => ['type' => 'boolean', 'value' => true],
            'database_viewer_show_functions' => ['type' => 'boolean', 'value' => true],
            'database_viewer_show_row_numbers' => ['type' => 'boolean', 'value' => true],
            'database_viewer_allow_export' => ['type' => 'boolean', 'value' => true],
            'database_viewer_history_retention_days' => ['type' => 'integer', 'value' => 30],

            // Network Tools (one toggle per test)
            'network_tools_enabled' => ['type' => 'boolean', 'value' => true],
            'network_tools_dns' => ['type' => 'boolean', 'value' => true],
            'network_tools_ping' => ['type' => 'boolean', 'value' => true],
            'network_tools_traceroute' => ['type' => 'boolean', 'value' => true],
            'network_tools_socket' => ['type' => 'boolean', 'value' => true],
            'network_tools_curl' => ['type' => 'boolean', 'value' => true],
            'network_tools_ssl_cert' => ['type' => 'boolean', 'value' => true],
            'network_tools_ciphers' => ['type' => 'boolean', 'value' => true],
            'network_tools_history_retention_days' => ['type' => 'integer', 'value' => 30],

            // Elastic Viewer
            'elastic_viewer_enabled' => ['type' => 'boolean', 'value' => true],
            'elastic_viewer_test' => ['type' => 'string', 'value' => ''],
            'elastic_viewer_allow_export' => ['type' => 'boolean', 'value' => true],

            // Redis Viewer (DSN is shown in clear; the password lives in its own encrypted field)
            'redis_viewer_enabled' => ['type' => 'boolean', 'value' => true],
            'redis_viewer_test' => ['type' => 'string', 'value' => ''],
            'redis_viewer_dsn' => ['type' => 'string', 'value' => ''],
            'redis_viewer_password' => ['type' => 'string', 'value' => ''],

            // MongoDB Viewer (two DSNs for the OroCloud public/private network scope; each DSN is
            // shown in clear and carries its own encrypted password field)
            'mongodb_viewer_enabled' => ['type' => 'boolean', 'value' => true],
            'mongodb_viewer_test' => ['type' => 'string', 'value' => ''],
            'mongodb_viewer_public_dsn' => ['type' => 'string', 'value' => ''],
            'mongodb_viewer_public_password' => ['type' => 'string', 'value' => ''],
            'mongodb_viewer_private_dsn' => ['type' => 'string', 'value' => ''],
            'mongodb_viewer_private_password' => ['type' => 'string', 'value' => ''],

            // Filesystem Viewer
            'filesystem_viewer_enabled' => ['type' => 'boolean', 'value' => true],
            'filesystem_viewer_base_path' => ['type' => 'string', 'value' => ''],
            'filesystem_viewer_restricted' => ['type' => 'boolean', 'value' => true],
            'filesystem_viewer_col_type' => ['type' => 'boolean', 'value' => true],
            'filesystem_viewer_col_filename' => ['type' => 'boolean', 'value' => true],
            'filesystem_viewer_col_filesize' => ['type' => 'boolean', 'value' => true],
            'filesystem_viewer_col_modified' => ['type' => 'boolean', 'value' => true],
            'filesystem_viewer_col_created' => ['type' => 'boolean', 'value' => true],
            'filesystem_viewer_col_owner_user' => ['type' => 'boolean', 'value' => true],
            'filesystem_viewer_col_owner_group' => ['type' => 'boolean', 'value' => true],
            'filesystem_viewer_col_preview' => ['type' => 'boolean', 'value' => true],

            // Bucket Viewer
            'bucket_viewer_enabled' => ['type' => 'boolean', 'value' => true],
            'bucket_viewer_test' => ['type' => 'string', 'value' => ''],
            'bucket_viewer_url' => ['type' => 'string', 'value' => 'http://minio:9000'],
            'bucket_viewer_user' => ['type' => 'string', 'value' => ''],
            'bucket_viewer_pass' => ['type' => 'string', 'value' => ''],
            'bucket_viewer_name' => ['type' => 'string', 'value' => ''],
            'bucket_viewer_read_only' => ['type' => 'boolean', 'value' => false],

            // Runtime Config
            'runtime_config_enabled' => ['type' => 'boolean', 'value' => true],

            // Connection Info
            'connection_info_enabled' => ['type' => 'boolean', 'value' => true],
        ]);

        return $treeBuilder;
    }
}
