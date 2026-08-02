<?php

namespace App\Service;

use App\Entity\GreekSnapshot;
use App\Entity\Position;
use App\Message\ClosePositionCommand;
use App\Repository\PositionRepository;
use App\Service\Broker\BrokerApiClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class PositionMonitorService
{
    public function __construct(
        private PositionRepository $positionRepository,
        private BrokerApiClientInterface $brokerClient,
        private MessageBusInterface $bus,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    public function evaluateOpenPositions(): int
    {
        $openPositions = $this->positionRepository->findBy(['status' => Position::STATE_OPEN]);
        $evaluatedCount = 0;

        foreach ($openPositions as $position) {
            try {
                $this->evaluatePosition($position);
                $evaluatedCount++;
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Error evaluating Position #%d: %s', $position->id, $e->getMessage()));
            }
        }

        return $evaluatedCount;
    }

    /**
     * @throws ExceptionInterface
     */
    private function evaluatePosition(Position $position): void
    {
        $quote = $this->brokerClient->getOptionQuote($position->contract->osiSymbol);

        // For short options (CSPs/CCs), ask price represents the cost to buy back and close
        $currentAsk = (float) $quote['ask'];
        $targetProfit = (float) $position->targetProfitPrice;
        $stopLoss = (float) $position->stopLossPrice;

        // 1. Record GreekSnapshot for auditing and performance analysis
        $snapshot = new GreekSnapshot(
            position: $position,
            delta: $quote['delta'] ?? '0.0000',
            gamma: $quote['gamma'] ?? '0.0000',
            theta: $quote['theta'] ?? '0.0000',
            vega: $quote['vega'] ?? '0.0000',
            impliedVolatility: $quote['iv'] ?? '0.0000',
            underlyingPrice: $quote['last'] ?? '0.00'
        );
        $this->entityManager->persist($snapshot);
        $this->entityManager->flush();

        // 2. Check Rule 1: 50% Max Profit Target Hit (Cost to buy back <= target price)
        if ($currentAsk > 0.0 && $currentAsk <= $targetProfit) {
            $this->logger->notice(sprintf(
                'Position #%d reached 50%% profit target! Current Ask: $%s | Target: $%s',
                $position->id,
                $quote['ask'],
                $position->targetProfitPrice
            ));

            $this->bus->dispatch(new ClosePositionCommand(
                positionId: $position->id,
                reason: 'PROFIT_TARGET_50_PERCENT'
            ));

            return;
        }

        // 3. Check Rule 2: Hard Stop Loss Breach (Cost to buy back >= stop limit)
        if ($stopLoss > 0.0 && $currentAsk >= $stopLoss) {
            $this->logger->warning(sprintf(
                'Position #%d breached stop loss limit! Current Ask: $%s | Stop Price: $%s',
                $position->id,
                $quote['ask'],
                $position->stopLossPrice
            ));

            $this->bus->dispatch(new ClosePositionCommand(
                positionId: $position->id,
                reason: 'STOP_LOSS_REACHED'
            ));
        }
    }
}
