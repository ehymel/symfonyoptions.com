<?php

namespace App\Command;

use App\Service\PositionMonitorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:positions:monitor-exits',
    description: 'Polls market pricing for open options positions and dispatches exit commands if profit/stop rules trigger.'
)]
final class MonitorPositionExitsCommand extends Command
{
    public function __construct(
        private readonly PositionMonitorService $monitorService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $count = $this->monitorService->evaluateOpenPositions();
            $io->success(sprintf('Evaluated %d open positions for exit rules.', $count));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed monitoring position exits: %s', $e->getMessage()));

            return Command::FAILURE;
        }
    }
}
