<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class OptionContract
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 10)]
    public private(set) string $symbol;

    #[ORM\Column(length: 32, unique: true)]
    public private(set) string $osiSymbol;

    #[ORM\Column(length: 4)]
    public private(set) string $optionType; // 'PUT' or 'CALL'

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    public private(set) string $strikePrice;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    public private(set) \DateTimeImmutable $expirationDate;

    public function __construct(
        string $symbol,
        string $osiSymbol,
        string $optionType,
        string $strikePrice,
        \DateTimeImmutable $expirationDate
    ) {
        $this->symbol = strtoupper($symbol);
        $this->osiSymbol = strtoupper($osiSymbol);
        $this->optionType = strtoupper($optionType);
        $this->strikePrice = $strikePrice;
        $this->expirationDate = $expirationDate;
    }

    public function getDaysToExpiration(): int
    {
        $now = new \DateTimeImmutable('today');
        return (int) $now->diff($this->expirationDate)->format('%r%a');
    }
}
