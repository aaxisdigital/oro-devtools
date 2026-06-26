<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Feature;

use Oro\Bundle\FeatureToggleBundle\Checker\Voter\VoterInterface;

/**
 * Grants the master "aaxis_devtools" feature when {@see DevToolsAccessCheckerInterface}
 * allows access; abstains otherwise.
 *
 * The master feature uses the "affirmative" strategy with allow_if_all_abstain=false
 * (see features.yml), so:
 *   - access allowed  -> FEATURE_ENABLED -> toolbox on,
 *   - access denied    -> FEATURE_ABSTAIN -> nobody grants it -> toolbox off (default).
 *
 * Abstaining (rather than voting DISABLED) on denial is deliberate: it keeps the gate a
 * pure opt-in, so the consuming app can also grant access via additional voters of its own
 * if it ever wants to, without this one vetoing them.
 */
final class DevToolsFeatureVoter implements VoterInterface
{
    private const MASTER_FEATURE = 'aaxis_devtools';

    public function __construct(private readonly DevToolsAccessCheckerInterface $accessChecker)
    {
    }

    #[\Override]
    public function vote($feature, $scopeIdentifier = null)
    {
        if (self::MASTER_FEATURE !== $feature) {
            return self::FEATURE_ABSTAIN;
        }

        return $this->accessChecker->isAccessAllowed()
            ? self::FEATURE_ENABLED
            : self::FEATURE_ABSTAIN;
    }
}
