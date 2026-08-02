<?php

namespace App\Service;

use App\DTO\OrderFillWebhookPayload;
use App\Entity\ExecutionLog;
use App\Entity\Position;
use App\Repository\PositionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\WorkflowInterface;

final readonly class WebhookOrderProcessorService
{
    public function __construct(
        private PositionRepository $positionRepository,
        private WorkflowInterface $optionPositionStateStateMachine,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    public function processFillNotification(OrderFillWebhookPayload $payload): bool
    {
        // 1. Locate the position tied to this broker order ID
        $position = $this->positionRepository->findOneBy(['openOrderId' => $payload->brokerOrderId])
            ?? $this->positionRepository->findOneBy(['closeOrderId' => $payload->brokerOrderId]);

        if (!$position) {
            $this->logger->warning('Webhook received for untracked broker order ID.', [
                'broker_order_id' => $payload->brokerOrderId,
                'status' => $payload->eventStatus,
            ]);

            // Log unmatched webhook payload for auditing
            $log = new ExecutionLog(
                action: 'WEBHOOK_UNMATCHED',
                status: 'WARNING',
                requestPayload: $payload->rawPayload,
                orderId: $payload->brokerOrderId
            );
            $this->entityManager->persist($log);
            $this->entityManager->flush();

            return false;
        }

        // 2. Handle Opening Order Fill (order_pending -> open)
        if ($position->openOrderId === $payload->brokerOrderId && $payload->eventStatus === 'FILLED') {
            if ($this->optionPositionStateStateMachine->can($position, 'fill_order')) {
                $position->markOpened($payload->avgFillPrice);
                $this->optionPositionStateStateMachine->apply($position, 'fill_order');

                $log = new ExecutionLog(
                    action: 'WEBHOOK_FILL_OPEN',
                    status: 'SUCCESS',
                    requestPayload: $payload->rawPayload,
                    orderId: $payload->brokerOrderId
                );

                $this->entityManager->persist($log);
                $this->entityManager->flush();

                $this->logger->info(sprintf(
                    'Position #%d successfully OPENED at avg price $%s via webhook.',
                    $position->id,
                    $payload->avgFillPrice
                ));

                return true;
            }
        }

        // 3. Handle Closing Order Fill (closing_pending -> closed)
        if ($position->closeOrderId === $payload->brokerOrderId && $payload->eventStatus === 'FILLED') {
            if ($this->optionPositionStateStateMachine->can($position, 'fill_close_order')) {
                $position->markClosed($payload->avgFillPrice);
                $this->optionPositionStateStateMachine->apply($position, 'fill_close_order');

                $log = new ExecutionLog(
                    action: 'WEBHOOK_FILL_CLOSE',
                    status: 'SUCCESS',
                    requestPayload: $payload->rawPayload,
                    orderId: $payload->brokerOrderId
                );

                $this->entityManager->persist($log);
                $this->entityManager->flush();

                $this->logger->info(sprintf(
                    'Position #%d successfully CLOSED at avg price $%s via webhook.',
                    $position->id,
                    $payload->avgFillPrice
                ));

                return true;
            }
        }

        // 4. Handle Rejected/Canceled Order
        if (in_array($payload->eventStatus, ['CANCELED', 'REJECTED', 'EXPIRED'], true)) {
            if ($position->status === Position::STATE_ORDER_PENDING && $this->optionPositionStateStateMachine->can($position, 'reject_order')) {
                $this->optionPositionStateStateMachine->apply($position, 'reject_order');
            }

            $log = new ExecutionLog(
                action: 'WEBHOOK_ORDER_' . $payload->eventStatus,
                status: 'NOTICE',
                requestPayload: $payload->rawPayload,
                orderId: $payload->brokerOrderId
            );

            $this->entityManager->persist($log);
            $this->entityManager->flush();
        }

        return true;
    }
}
