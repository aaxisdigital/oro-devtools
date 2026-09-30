<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Feature;

use Aaxis\Bundle\DevToolsBundle\Feature\DenyAllDevToolsAccessChecker;
use Aaxis\Bundle\DevToolsBundle\Feature\DevToolsAccessCheckerInterface;
use Aaxis\Bundle\DevToolsBundle\Feature\DevToolsFeatureVoter;
use Oro\Bundle\FeatureToggleBundle\Checker\Voter\VoterInterface;
use PHPUnit\Framework\TestCase;

class DevToolsFeatureVoterTest extends TestCase
{
    public function testEnablesTheMasterFeatureWhenAccessIsAllowed(): void
    {
        $voter = new DevToolsFeatureVoter($this->checker(true));

        self::assertSame(VoterInterface::FEATURE_ENABLED, $voter->vote('aaxis_devtools'));
    }

    public function testAbstainsRatherThanDisablesWhenAccessIsDenied(): void
    {
        $voter = new DevToolsFeatureVoter($this->checker(false));

        self::assertSame(VoterInterface::FEATURE_ABSTAIN, $voter->vote('aaxis_devtools'));
    }

    public function testNeverConsultsTheCheckerForOtherFeatures(): void
    {
        $checker = $this->createMock(DevToolsAccessCheckerInterface::class);
        $checker->expects(self::never())->method('isAccessAllowed');

        self::assertSame(VoterInterface::FEATURE_ABSTAIN, (new DevToolsFeatureVoter($checker))->vote('aaxis_devtools_redis_viewer'));
    }

    public function testTheDefaultCheckerDeniesEveryone(): void
    {
        $voter = new DevToolsFeatureVoter(new DenyAllDevToolsAccessChecker());

        self::assertFalse((new DenyAllDevToolsAccessChecker())->isAccessAllowed());
        self::assertSame(VoterInterface::FEATURE_ABSTAIN, $voter->vote('aaxis_devtools'));
    }

    private function checker(bool $allowed): DevToolsAccessCheckerInterface
    {
        $checker = $this->createMock(DevToolsAccessCheckerInterface::class);
        $checker->method('isAccessAllowed')->willReturn($allowed);

        return $checker;
    }
}
