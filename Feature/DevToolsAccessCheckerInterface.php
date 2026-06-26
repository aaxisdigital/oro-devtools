<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Feature;

/**
 * Decides whether the Dev Tools toolbox is available for the current request/context.
 *
 * This is the single extension point that gates the whole bundle. The bundle ships
 * {@see DenyAllDevToolsAccessChecker} (always denies), so the toolbox is OFF by default.
 * The consuming application enables it by overriding the
 * "aaxis_devtools.feature.access_checker" service with its own implementation
 * (typically host/IP/environment based) — see the bundle README.
 *
 * The result feeds {@see DevToolsFeatureVoter}, which votes on the master "aaxis_devtools"
 * feature. Every tool feature depends on that master feature, so a single "false" here
 * 404s every tool route and hides the whole menu group.
 */
interface DevToolsAccessCheckerInterface
{
    public function isAccessAllowed(): bool;
}
