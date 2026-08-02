<?php

namespace App\Service;

use App\DTO\ScreenerCriteria;
use App\Entity\OptionContract;
use App\Entity\Position;
use App\Repository\AccountBalanceRepository;
use App\Repository\PositionRepository;
use App\Service\Broker\BrokerApiClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class OptionScreenerService
{
    public function __construct(
        private BrokerApiClientInterface $brokerClient,
        private AccountBalanceRepository $accountBalanceRepository,
        private PositionRepository $positionRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    /**
     * Scans options chains for a ticker and generates proposed Cash-Secured Put positions.
     *
     * @return array<int, Position>
     */
    public function scanForCashSecuredPuts(ScreenerCriteria $criteria): array
    {
        $symbol = strtoupper($criteria->symbol);

        // 1. Check existing position exposure for this symbol
        $activeSymbolPositions = $this->positionRepository->countActivePositionsBySymbol($symbol);
        if ($activeSymbolPositions >= $criteria->maxPositionsPerSymbol) {
            $this->logger->info(sprintf(
                'Screener skipped %s: Max position count (%d) reached.',
                $symbol,
                $criteria->maxPositionsPerSymbol
            ));
            return [];
        }

        // 2. Fetch the latest Account Balance snapshot to check cash limits
        $latestBalance = $this->accountBalanceRepository->findLatestSnapshot();
        if (!$latestBalance) {
            $this->logger->error('Screener halted: No AccountBalance snapshot found in database.');
            return [];
        }

        $availableCash = $latestBalance->getAvailableCashForNewPositions();
        if ($availableCash <= 0) {
            $this->logger->warning('Screener skipped: Insufficient unencumbered cash balance.');
            return [];
        }

        // 3. Fetch Option Chain from Broker API
        $rawChain = $this->brokerClient->getOptionChain($symbol);
        $proposedPositions = [];

        foreach ($rawChain as $contractData) {
            // Filter strictly for PUT options
            if (strtoupper($contractData['option_type'] ?? '') !== 'PUT') {
                continue;
            }

            $expiration = new \DateTimeImmutable($contractData['expiration_date']);
            $dte = (int) (new \DateTimeImmutable('today'))->diff($expiration)->format('%r%a');

            // Filter A: Days To Expiration (30 to 45 DTE)
            if ($dte < $criteria->minDte || $dte > $criteria->maxDte) {
                continue;
            }

            // Filter B: Delta Target (e.g., -0.20 <= delta <= -0.15)
            $delta = (float) ($contractData['greeks']['delta'] ?? 0.0);
            if ($delta < $criteria->minDelta || $delta > $criteria->maxDelta) {
                continue;
            }

            // Filter C: Implied Volatility Rank (if provided by market data provider)
            $ivRank = (float) ($contractData['iv_rank'] ?? 100.0);
            if ($ivRank < $criteria->minIvRank) {
                continue;
            }

            $strikePrice = (float) $contractData['strike_price'];

            // 4. Verify Capital Allocation Rule: 100 * Strike Price <= Available Cash
            if (!$latestBalance->canCoverCashSecuredPut($strikePrice, 1)) {
                $this->logger->notice(sprintf(
                    'Screener skipped contract %s: Required collateral ($%s) exceeds available cash ($%s).',
                    $contractData['osi_symbol'],
                    number_format($strikePrice * 100, 2),
                    number_format($availableCash, 2)
                ));
                continue;
            }

            // 5. Build domain entities
            $contract = new OptionContract(
                symbol: $symbol,
                osiSymbol: $contractData['osi_symbol'],
                optionType: 'PUT',
                strikePrice: number_format($strikePrice, 2, '.', ''),
                expirationDate: $expiration
            );

            $position = new Position(
                contract: $contract,
                strategyType: 'CASH_SECURED_PUT',
                quantity: 1
            );

            $this->entityManager->persist($contract);
            $this->entityManager->persist($position);

            $proposedPositions[] = $position;

            $this->logger->info(sprintf(
                'Proposed CSP generated: %s (Strike: $%s, DTE: %d, Delta: %.2f)',
                $contract->osiSymbol,
                $contract->strikePrice,
                $dte,
                $delta
            ));

            // Propose one optimal contract per scan run to manage risk
            break;
        }

        if (!empty($proposedPositions)) {
            $this->entityManager->flush();
        }

        return $proposedPositions;
    }
}
