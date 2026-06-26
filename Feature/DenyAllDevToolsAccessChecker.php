<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Feature;

/**
 * Default access checker: denies everyone, everywhere.
 *
 * This keeps the toolbox disabled out of the box (these tools expose DB / filesystem /
 * storage / Redis / Mongo / Elastic / network internals, so "off unless explicitly enabled"
 * is the safe default). The consuming application overrides the
 * "aaxis_devtools.feature.access_checker" service to opt in.
 */
final class DenyAllDevToolsAccessChecker implements DevToolsAccessCheckerInterface
{
    #[\Override]
    public function isAccessAllowed(): bool
    {
        return false;
    }
}
