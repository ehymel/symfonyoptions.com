<?php

namespace App\Entity;

use App\Repository\AccountBalanceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountBalanceRepository::class)]
#[ORM\Index(name: 'idx_balance_snapshot_at', columns: ['snapshot_at'])]
class AccountBalance
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    public private(set) string $netLiquidationValue;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    public private(set) string $cashBalance;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    public private(set) string $optionBuyingPower;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    public private(set) string $reservedCashForPuts; // Committed collateral for open CSPs

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $snapshotAt;

    public function __construct(
        string $netLiquidationValue,
        string $cashBalance,
        string $optionBuyingPower,
        string $reservedCashForPuts = '0.00'
    ) {
        $this->netLiquidationValue = $netLiquidationValue;
        $this->cashBalance = $cashBalance;
        $this->optionBuyingPower = $optionBuyingPower;
        $this->reservedCashForPuts = $reservedCashForPuts;
        $this->snapshotAt = new \DateTimeImmutable();
    }

    /**
     * Calculates available unencumbered cash reserved for new Cash-Secured Puts.
     */
    public function getAvailableCashForNewPositions(): float
    {
        $cash = (float) $this->cashBalance;
        $reserved = (float) $this->reservedCashForPuts;

        return max(0.0, $cash - $reserved);
    }

    /**
     * Safety check to ensure a proposed cash-secured put order does not over-leverage cash reserves.
     */
    public function canCoverCashSecuredPut(float $strikePrice, int $contracts = 1): bool
    {
        $requiredCapital = $strikePrice * 100 * $contracts;

        return $this->getAvailableCashForNewPositions() >= $requiredCapital;
    }
}
