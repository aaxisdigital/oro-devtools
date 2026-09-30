<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Command;

use Aaxis\Bundle\CommonBundle\Command\HistoryRetentionPurger;
use Aaxis\Bundle\DevToolsBundle\Command\CleanupHistoryCommand;
use Aaxis\Bundle\DevToolsBundle\Entity\NetworkTestHistory;
use Aaxis\Bundle\DevToolsBundle\Entity\QueryHistory;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CleanupHistoryCommandTest extends TestCase
{
    private HistoryRetentionPurger&MockObject $purger;
    private ConfigManager&MockObject $config;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->purger = $this->createMock(HistoryRetentionPurger::class);
        $this->config = $this->createMock(ConfigManager::class);
        $this->tester = new CommandTester(new CleanupHistoryCommand($this->purger, $this->config));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function configure(array $values): void
    {
        $this->config->method('get')->willReturnCallback(static fn (string $key) => $values[$key] ?? null);
    }

    public function testIsRegisteredUnderItsNameAndRunsDailyAtMidnight(): void
    {
        $command = new CleanupHistoryCommand($this->purger, $this->config);

        self::assertSame('aaxis:devtools:history:cleanup', $command->getName());
        self::assertSame('0 0 * * *', $command->getDefaultDefinition());
    }

    public function testPurgesEachHistoryTableWithItsConfiguredRetention(): void
    {
        $this->configure([
            'aaxis_devtools.database_viewer_history_retention_days' => '30',
            'aaxis_devtools.network_tools_history_retention_days' => 7,
        ]);
        $calls = [];
        $this->purger->expects(self::exactly(2))->method('purge')->willReturnCallback(
            static function (string $class, int $days) use (&$calls): int {
                $calls[$class] = $days;

                return $class === QueryHistory::class ? 4 : 2;
            }
        );

        $status = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([QueryHistory::class => 30, NetworkTestHistory::class => 7], $calls);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Database Viewer: removed 4 record(s) older than 30 day(s).', $display);
        self::assertStringContainsString('Network Tools: removed 2 record(s) older than 7 day(s).', $display);
        self::assertStringContainsString('Removed 6 expired history record(s).', $display);
    }

    /**
     * @dataProvider disabledRetentionProvider
     */
    public function testARetentionOfZeroOrLessKeepsEverything(mixed $days): void
    {
        $this->configure([
            'aaxis_devtools.database_viewer_history_retention_days' => $days,
            'aaxis_devtools.network_tools_history_retention_days' => $days,
        ]);
        $this->purger->expects(self::never())->method('purge');

        $status = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Database Viewer: retention disabled, keeping all records.', $display);
        self::assertStringContainsString('Network Tools: retention disabled, keeping all records.', $display);
        self::assertStringContainsString('Removed 0 expired history record(s).', $display);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function disabledRetentionProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
        yield 'unset' => [null];
        yield 'garbage' => ['never'];
    }

    public function testOneToolCanBeDisabledWhileTheOtherIsPurged(): void
    {
        $this->configure(['aaxis_devtools.network_tools_history_retention_days' => 10]);
        $this->purger->expects(self::once())->method('purge')->with(NetworkTestHistory::class, 10)->willReturn(5);

        $this->tester->execute([]);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Database Viewer: retention disabled', $display);
        self::assertStringContainsString('Removed 5 expired history record(s).', $display);
    }
}
