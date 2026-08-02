<?php

namespace App\Message;

final readonly class SubmitOrderCommand
{
    public function __construct(
        public int $positionId
    ) {}
}
