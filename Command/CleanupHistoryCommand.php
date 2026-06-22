<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Command;

use Aaxis\Bundle\CommonBundle\Command\HistoryRetentionPurger;
use Aaxis\Bundle\DevToolsBundle\Entity\NetworkTestHistory;
use Aaxis\Bundle\DevToolsBundle\Entity\QueryHistory;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\CronBundle\Command\CronCommandScheduleDefinitionInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Nightly cleanup of the dev tools' history tables. For each tool, records whose run date is older
 * than the configured retention (in days) are removed. A retention of 0 keeps records forever.
 */
#[AsCommand(
    name: 'aaxis:devtools:history:cleanup',
    description: 'Removes Aaxis Dev Tools history records older than the configured retention.'
)]
class CleanupHistoryCommand extends Command implements CronCommandScheduleDefinitionInterface
{
    public function __construct(
        private readonly HistoryRetentionPurger $purger,
        private readonly ConfigManager $configManager,
    ) {
        parent::__construct();
    }

    // Runs once a day at midnight.
    #[\Override]
    public function getDefaultDefinition(): string
    {
        return '0 0 * * *';
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $total = 0;
        $total += $this->purge($io, QueryHistory::class, 'aaxis_devtools.database_viewer_history_retention_days', 'Database Viewer');
        $total += $this->purge($io, NetworkTestHistory::class, 'aaxis_devtools.network_tools_history_retention_days', 'Network Tools');

        $io->success(sprintf('Removed %d expired history record(s).', $total));

        return Command::SUCCESS;
    }

    /**
     * @param class-string $entityClass
     */
    private function purge(SymfonyStyle $io, string $entityClass, string $configKey, string $label): int
    {
        $days = (int) $this->configManager->get($configKey);
        if ($days <= 0) {
            $io->writeln(sprintf('%s: retention disabled, keeping all records.', $label));

            return 0;
        }

        $deleted = $this->purger->purge($entityClass, $days);
        $io->writeln(sprintf('%s: removed %d record(s) older than %d day(s).', $label, $deleted, $days));

        return $deleted;
    }
}
