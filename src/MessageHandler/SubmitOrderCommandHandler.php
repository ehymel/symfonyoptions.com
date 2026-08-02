<?php

namespace App\MessageHandler;

use App\Entity\ExecutionLog;
use App\Message\SubmitOrderCommand;
use App\Repository\PositionRepository;
use App\Service\Broker\BrokerApiClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Workflow\WorkflowInterface;

#[AsMessageHandler]
final readonly class SubmitOrderCommandHandler
{
    public function __construct(
        private PositionRepository $positionRepository,
        private BrokerApiClientInterface $brokerClient,
        private WorkflowInterface $optionPositionStateStateMachine, // Autowired workflow
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    public function __invoke(SubmitOrderCommand $message): void
    {
        $position = $this->positionRepository->find($message->positionId);

        if (!$position) {
            // Throwing UnrecoverableMessageHandlingException prevents retrying a non-existent ID
            throw new UnrecoverableMessageHandlingException(
                sprintf('Position with ID %d not found.', $message->positionId)
            );
        }

        // 1. Check if the workflow allows transitioning from 'proposed' -> 'order_pending'
        if (!$this->optionPositionStateStateMachine->can($position, 'submit_order')) {
            $this->logger->warning('Cannot submit order: invalid state transition.', [
                'position_id' => $position->id,
                'current_status' => $position->status,
            ]);
            return;
        }

        // 2. Build the order payload for the broker
        $contract = $position->contract;
        $orderPayload = [
            'class' => 'option',
            'symbol' => $contract->symbol,
            'option_symbol' => $contract->osiSymbol,
            'side' => $contract->optionType === 'PUT' ? 'sell_to_open' : 'sell_to_open',
            'quantity' => $position->quantity,
            'type' => 'market', // Or limit based on bid/ask
            'duration' => 'day',
        ];

        try {
            // 3. Dispatch to Broker API
            $response = $this->brokerClient->placeOptionOrder($orderPayload);
            $brokerOrderId = $response['id'] ?? null;

            if (!$brokerOrderId) {
                throw new \RuntimeException('Broker response did not contain an order ID.');
            }

            // 4. Update domain state & apply workflow transition
            $position->markOrderPending($brokerOrderId);
            $this->optionPositionStateStateMachine->apply($position, 'submit_order');

            // 5. Audit log
            $log = new ExecutionLog(
                action: 'SUBMIT_ORDER',
                status: 'SUCCESS',
                requestPayload: $orderPayload,
                responsePayload: $response,
                orderId: $brokerOrderId
            );

            $this->entityManager->persist($log);
            $this->entityManager->flush();

            $this->logger->info(sprintf('Order submitted successfully for position #%d', $position->id), [
                'broker_order_id' => $brokerOrderId,
            ]);

        } catch (\Throwable $e) {
            // Log failure payload for debugging
            $failedLog = new ExecutionLog(
                action: 'SUBMIT_ORDER',
                status: 'FAILED',
                requestPayload: $orderPayload,
                responsePayload: ['error' => $e->getMessage()]
            );

            $this->entityManager->persist($failedLog);
            $this->entityManager->flush();

            $this->logger->error(sprintf('Failed to submit order for position #%d: %s', $position->id, $e->getMessage()));

            // Re-throw to trigger Symfony Messenger retry strategy if configured
            throw $e;
        }
    }
}
