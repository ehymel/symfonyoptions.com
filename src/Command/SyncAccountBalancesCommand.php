<?php

namespace App\Command;

use App\Service\AccountBalanceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:account:sync-balances',
    description: 'Polls the broker API for balance data and logs an AccountBalance snapshot to MariaDB.'
)]
final class SyncAccountBalancesCommand extends Command
{
    public function __construct(
        private readonly AccountBalanceService $balanceService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Syncing Account Balances');

        try {
            $balance = $this->balanceService->recordBalanceSnapshot();

            $io->success(sprintf(
                'Balance snapshot recorded! Net Liq: $%s | Cash: $%s | Reserved Collateral: $%s | Available Cash: $%s',
                number_format((float) $balance->netLiquidationValue, 2),
                number_format((float) $balance->cashBalance, 2),
                number_format((float) $balance->reservedCashForPuts, 2),
                number_format($balance->getAvailableCashForNewPositions(), 2)
            ));

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error(sprintf('Failed to sync account balances: %s', $e->getMessage()));

            return Command::FAILURE;
        }
    }
}
