<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle;

use Aaxis\Bundle\DevToolsBundle\DependencyInjection\AaxisDevToolsExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The AaxisDevToolsBundle adds the "Aaxis Dev Tools" section to the back-office (admin) application
 * menu: a set of operational / developer tools (Runtime Config, Filesystem & Bucket browsers,
 * Database / Elastic / Redis / MongoDB viewers and Network Tools).
 *
 * It was split out of AaxisToolsBundle and is independent of it; both require AaxisCommonBundle.
 */
class AaxisDevToolsBundle extends Bundle
{
    /**
     * Return the extension explicitly so its "aaxis_devtools" alias is used as-is. The default
     * implementation would reject it for not matching the underscored bundle name ("aaxis_dev_tools").
     */
    #[\Override]
    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            $this->extension = new AaxisDevToolsExtension();
        }

        return $this->extension ?: null;
    }
}
