<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(name: 'idx_greek_recorded_at', columns: ['recorded_at'])]
class GreekSnapshot
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Position::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) Position $position;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 4)]
    public private(set) string $delta;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 4)]
    public private(set) string $gamma;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 4)]
    public private(set) string $theta;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 4)]
    public private(set) string $vega;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 4)]
    public private(set) string $impliedVolatility;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    public private(set) string $underlyingPrice;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $recordedAt;

    public function __construct(
        Position $position,
        string $delta,
        string $gamma,
        string $theta,
        string $vega,
        string $impliedVolatility,
        string $underlyingPrice
    ) {
        $this->position = $position;
        $this->delta = $delta;
        $this->gamma = $gamma;
        $this->theta = $theta;
        $this->vega = $vega;
        $this->impliedVolatility = $impliedVolatility;
        $this->underlyingPrice = $underlyingPrice;
        $this->recordedAt = new \DateTimeImmutable();
    }
}
