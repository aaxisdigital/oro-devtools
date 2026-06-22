<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\DependencyInjection;

use Oro\Bundle\ConfigBundle\DependencyInjection\SettingsBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Loads and manages the bundle configuration.
 * The alias resolves to "aaxis_devtools".
 */
class AaxisDevToolsExtension extends Extension
{
    #[\Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        $container->prependExtensionConfig($this->getAlias(), SettingsBuilder::getSettings($config));

        // Absolute path to this bundle's root, resolved wherever the package is installed
        // (src/… in a monorepo, vendor/aaxisdigital/oro-devtools when pulled via Composer).
        // Used to point the TypeScript build at this bundle's own tsconfig.
        $container->setParameter('aaxis_devtools.bundle_dir', \dirname(__DIR__));

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');
        $loader->load('controllers.yml');
    }

    /**
     * Force the "aaxis_devtools" alias. Symfony would otherwise derive "aaxis_dev_tools" from the
     * extension class name, which would not match the System Configuration setting keys.
     */
    #[\Override]
    public function getAlias(): string
    {
        return 'aaxis_devtools';
    }
}
