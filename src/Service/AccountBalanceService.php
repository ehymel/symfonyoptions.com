<?php

namespace App\Service;

use App\Entity\AccountBalance;
use App\Entity\Position;
use App\Repository\PositionRepository;
use App\Service\Broker\BrokerApiClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class AccountBalanceService
{
    public function __construct(
        private BrokerApiClientInterface $brokerClient,
        private PositionRepository $positionRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    public function recordBalanceSnapshot(): AccountBalance
    {
        // 1. Fetch current balances from the broker API
        $rawBalances = $this->brokerClient->getAccountBalances();

        // 2. Calculate collateral currently locked in open Cash-Secured Puts
        $reservedCash = $this->calculateReservedCashForOpenPuts();

        // 3. Instantiate the AccountBalance entity
        $balance = new AccountBalance(
            netLiquidationValue: $rawBalances['net_liquidation_value'],
            cashBalance: $rawBalances['cash_balance'],
            optionBuyingPower: $rawBalances['option_buying_power'],
            reservedCashForPuts: number_format($reservedCash, 2, '.', '')
        );

        // 4. Persist to MariaDB
        $this->entityManager->persist($balance);
        $this->entityManager->flush();

        $this->logger->info('Account balance snapshot recorded successfully.', [
            'net_liq' => $balance->netLiquidationValue,
            'cash' => $balance->cashBalance,
            'reserved_cash' => $balance->reservedCashForPuts,
            'available_cash' => $balance->getAvailableCashForNewPositions(),
        ]);

        return $balance;
    }

    /**
     * Sums total cash needed to secure all active CSP positions: (Strike * 100 * Quantity)
     */
    private function calculateReservedCashForOpenPuts(): float
    {
        $openPositions = $this->positionRepository->findBy([
            'strategyType' => 'CASH_SECURED_PUT',
            'status' => [Position::STATE_ORDER_PENDING, Position::STATE_OPEN, Position::STATE_CLOSING_PENDING],
        ]);

        $totalReserved = 0.0;

        foreach ($openPositions as $position) {
            $strike = (float) $position->contract->strikePrice;
            $totalReserved += ($strike * 100 * $position->quantity);
        }

        return $totalReserved;
    }
}
