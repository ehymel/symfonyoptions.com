<?php

namespace App\DTO;

final readonly class ScreenerCriteria
{
    public function __construct(
        public string $symbol,
        public int $minDte = 30,
        public int $maxDte = 45,
        public float $minDelta = -0.20,
        public float $maxDelta = -0.15,
        public float $minIvRank = 30.0, // Minimum Implied Volatility Rank (percentile)
        public int $maxPositionsPerSymbol = 1
    ) {}
}
