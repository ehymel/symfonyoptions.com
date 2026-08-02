<?php

namespace App\Entity;

use App\Repository\PositionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PositionRepository::class)]
#[ORM\Index(columns: ['status'], name: 'idx_position_status')]
class Position
{
    // State constants matching standard Symfony Workflow states
    public const string STATE_PROPOSED = 'proposed';
    public const string STATE_ORDER_PENDING = 'order_pending';
    public const string STATE_OPEN = 'open';
    public const string STATE_CLOSING_PENDING = 'closing_pending';
    public const string STATE_CLOSED = 'closed';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OptionContract::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    public private(set) OptionContract $contract;

    #[ORM\Column(length: 20)]
    public private(set) string $strategyType; // e.g., 'CASH_SECURED_PUT', 'COVERED_CALL'

    #[ORM\Column(length: 32)]
    public string $status; // Managed via Symfony Workflow component or state transitions

    #[ORM\Column]
    public private(set) int $quantity;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    public private(set) ?string $entryPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    public private(set) ?string $targetProfitPrice = null; // 50% max profit target price

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    public private(set) ?string $stopLossPrice = null; // Hard stop limit price

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    public private(set) ?string $closePrice = null;

    #[ORM\Column(length: 64, nullable: true)]
    public private(set) ?string $openOrderId = null;

    #[ORM\Column(length: 64, nullable: true)]
    public private(set) ?string $closeOrderId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $openedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $closedAt = null;

    public function __construct(
        OptionContract $contract,
        string $strategyType,
        int $quantity = 1
    ) {
        $this->contract = $contract;
        $this->strategyType = $strategyType;
        $this->quantity = $quantity;
        $this->status = self::STATE_PROPOSED;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function markOrderPending(string $brokerOrderId): void
    {
        $this->openOrderId = $brokerOrderId;
        $this->status = self::STATE_ORDER_PENDING;
    }

    public function markOpened(string $entryPrice): void
    {
        $this->entryPrice = $entryPrice;
        $this->status = self::STATE_OPEN;
        $this->openedAt = new \DateTimeImmutable();

        // 50% profit target rule (for net short options)
        $this->targetProfitPrice = number_format((float) $entryPrice * 0.50, 2, '.', '');

        // 250% stop loss rule (e.g., max 2.5x premium loss)
        $this->stopLossPrice = number_format((float) $entryPrice * 2.50, 2, '.', '');
    }

    public function markClosingPending(string $closeOrderId): void
    {
        $this->closeOrderId = $closeOrderId;
        $this->status = self::STATE_CLOSING_PENDING;
    }

    public function markClosed(string $closePrice): void
    {
        $this->closePrice = $closePrice;
        $this->status = self::STATE_CLOSED;
        $this->closedAt = new \DateTimeImmutable();
    }
}
