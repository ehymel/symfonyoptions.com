<?php

namespace App\Command;

use App\DTO\ScreenerCriteria;
use App\Service\OptionScreenerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:options:scan-wheel-targets',
    description: 'Scans target tickers for Cash-Secured Put candidates and creates proposed positions.'
)]
final class ScanWheelTargetsCommand extends Command
{
    private const DEFAULT_WATCHLIST = ['SPY', 'QQQ', 'IWM', 'XLF', 'XLE'];

    public function __construct(
        private readonly OptionScreenerService $screenerService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbols', 's', InputOption::VALUE_OPTIONAL, 'Comma-separated list of ticker symbols to scan')
            ->addOption('min-dte', null, InputOption::VALUE_OPTIONAL, 'Minimum DTE', 30)
            ->addOption('max-dte', null, InputOption::VALUE_OPTIONAL, 'Maximum DTE', 45)
            ->addOption('min-delta', null, InputOption::VALUE_OPTIONAL, 'Min Delta', -0.20)
            ->addOption('max-delta', null, InputOption::VALUE_OPTIONAL, 'Max Delta', -0.15);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Scanning Market Options Chains (Wheel Strategy)');

        $symbolsInput = $input->getOption('symbols');
        $watchlist = $symbolsInput
            ? array_map('trim', explode(',', $symbolsInput))
            : self::DEFAULT_WATCHLIST;

        $totalProposed = 0;

        foreach ($watchlist as $symbol) {
            $io->section(sprintf('Scanning ticker: %s', $symbol));

            $criteria = new ScreenerCriteria(
                symbol: $symbol,
                minDte: (int) $input->getOption('min-dte'),
                maxDte: (int) $input->getOption('max-dte'),
                minDelta: (float) $input->getOption('min-delta'),
                maxDelta: (float) $input->getOption('max-delta')
            );

            $proposed = $this->screenerService->scanForCashSecuredPuts($criteria);
            $count = count($proposed);
            $totalProposed += $count;

            if ($count > 0) {
                $io->success(sprintf('Found %d viable Cash-Secured Put position for %s!', $count, $symbol));
            } else {
                $io->writeln(sprintf('No contracts matching criteria for %s.', $symbol));
            }
        }

        $io->newLine();
        $io->success(sprintf('Scan complete. Total proposed positions created: %d', $totalProposed));

        return Command::SUCCESS;
    }
}
