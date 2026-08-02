<?php

namespace App\Message;

final readonly class ClosePositionCommand
{
    public function __construct(
        public int $positionId,
        public string $reason // e.g., 'PROFIT_TARGET_50_PERCENT', 'STOP_LOSS_REACHED', 'EXPIRATION_SAFETY'
    ) {}
}
