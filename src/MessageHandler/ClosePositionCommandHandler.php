<?php

namespace App\MessageHandler;

use App\Entity\ExecutionLog;
use App\Entity\Position;
use App\Message\ClosePositionCommand;
use App\Repository\PositionRepository;
use App\Service\Broker\BrokerApiClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Workflow\WorkflowInterface;

#[AsMessageHandler]
final readonly class ClosePositionCommandHandler
{
    public function __construct(
        private PositionRepository $positionRepository,
        private BrokerApiClientInterface $brokerClient,
        private WorkflowInterface $optionPositionStateStateMachine,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    public function __invoke(ClosePositionCommand $message): void
    {
        $position = $this->positionRepository->find($message->positionId);

        if (!$position) {
            throw new UnrecoverableMessageHandlingException(
                sprintf('Position #%d not found.', $message->positionId)
            );
        }

        // Validate state machine allows transitioning from 'open' -> 'closing_pending'
        if (!$this->optionPositionStateStateMachine->can($position, 'submit_close_order')) {
            $this->logger->warning('Cannot submit close order: invalid state transition.', [
                'position_id' => $position->id,
                'current_status' => $position->status,
            ]);
            return;
        }

        $contract = $position->contract;

        // Options bought to open are sold to close; options sold to open (CSPs/CCs) are bought back to close
        $closeSide = match ($position->strategyType) {
            'CASH_SECURED_PUT', 'COVERED_CALL' => 'buy_to_close',
            default => 'sell_to_close',
        };

        $orderPayload = [
            'class' => 'option',
            'symbol' => $contract->symbol,
            'option_symbol' => $contract->osiSymbol,
            'side' => $closeSide,
            'quantity' => $position->quantity,
            'type' => 'market',
            'duration' => 'day',
        ];

        try {
            $response = $this->brokerClient->placeOptionOrder($orderPayload);
            $brokerOrderId = $response['id'] ?? null;

            if (!$brokerOrderId) {
                throw new \RuntimeException('Broker response missing order ID.');
            }

            // Update domain entity & apply workflow transition
            $position->markClosingPending($brokerOrderId);
            $this->optionPositionStateStateMachine->apply($position, 'submit_close_order');

            $log = new ExecutionLog(
                action: 'SUBMIT_CLOSE_ORDER (' . $message->reason . ')',
                status: 'SUCCESS',
                requestPayload: $orderPayload,
                responsePayload: $response,
                orderId: $brokerOrderId
            );

            $this->entityManager->persist($log);
            $this->entityManager->flush();

            $this->logger->info(sprintf('Close order submitted for Position #%d (%s)', $position->id, $message->reason), [
                'broker_order_id' => $brokerOrderId,
            ]);

        } catch (\Throwable $e) {
            $failedLog = new ExecutionLog(
                action: 'SUBMIT_CLOSE_ORDER_FAILED (' . $message->reason . ')',
                status: 'FAILED',
                requestPayload: $orderPayload,
                responsePayload: ['error' => $e->getMessage()]
            );

            $this->entityManager->persist($failedLog);
            $this->entityManager->flush();

            $this->logger->error(sprintf('Failed to close position #%d: %s', $position->id, $e->getMessage()));
            throw $e;
        }
    }
}
