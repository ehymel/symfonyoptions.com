<?php

namespace App\DTO;

final readonly class OrderFillWebhookPayload
{
    public function __construct(
        public string $brokerOrderId,
        public string $eventStatus, // e.g., 'FILLED', 'PARTIALLY_FILLED', 'CANCELED', 'REJECTED'
        public int $filledQuantity,
        public string $avgFillPrice,
        public \DateTimeImmutable $filledAt,
        public array $rawPayload = []
    ) {}
}
