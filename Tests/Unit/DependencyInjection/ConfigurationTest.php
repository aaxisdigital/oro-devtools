<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\DependencyInjection;

use Aaxis\Bundle\DevToolsBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

class ConfigurationTest extends TestCase
{
    /**
     * @return array<string, array{value: mixed}>
     */
    private function settings(array $configs = []): array
    {
        $config = (new Processor())->processConfiguration(new Configuration(), $configs);

        return $config['settings'];
    }

    public function testDefaultsOfTheVisibleSettings(): void
    {
        $settings = $this->settings();

        self::assertTrue($settings['database_viewer_show_views']['value']);
        self::assertSame(30, $settings['database_viewer_history_retention_days']['value']);
        self::assertSame(30, $settings['network_tools_history_retention_days']['value']);
        self::assertSame('http://minio:9000', $settings['bucket_viewer_url']['value']);
        self::assertFalse($settings['bucket_viewer_read_only']['value']);
        self::assertTrue($settings['filesystem_viewer_restricted']['value']);
        self::assertSame('', $settings['redis_viewer_dsn']['value']);
        self::assertSame('', $settings['mongodb_viewer_private_dsn']['value']);
    }

    /**
     * @dataProvider toolToggleProvider
     */
    public function testEveryToolCarriesAnEnabledToggle(string $key): void
    {
        self::assertTrue($this->settings()[$key]['value']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function toolToggleProvider(): iterable
    {
        foreach ([
            'database_viewer', 'network_tools', 'elastic_viewer', 'redis_viewer', 'mongodb_viewer',
            'filesystem_viewer', 'bucket_viewer', 'runtime_config', 'connection_info',
        ] as $tool) {
            yield $tool => [$tool . '_enabled'];
        }
    }

    public function testASettingCanBeOverridden(): void
    {
        $settings = $this->settings([['settings' => ['redis_viewer_dsn' => ['value' => 'redis://cache:6379']]]]);

        self::assertSame('redis://cache:6379', $settings['redis_viewer_dsn']['value']);
    }
}
