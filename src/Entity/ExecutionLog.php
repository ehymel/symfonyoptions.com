<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'execution_logs')]
class ExecutionLog
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 64, nullable: true)]
    public private(set) ?string $orderId = null;

    #[ORM\Column(length: 50)]
    public private(set) string $action; // e.g., 'SUBMIT_ORDER', 'CANCEL_ORDER', 'WEBHOOK_FILL'

    #[ORM\Column(length: 20)]
    public private(set) string $status; // 'SUCCESS', 'FAILED', 'RETRY'

    #[ORM\Column(type: Types::JSON)]
    public private(set) array $requestPayload = [];

    #[ORM\Column(type: Types::JSON)]
    public private(set) array $responsePayload = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    public function __construct(
        string $action,
        string $status,
        array $requestPayload = [],
        array $responsePayload = [],
        ?string $orderId = null
    ) {
        $this->action = $action;
        $this->status = $status;
        $this->requestPayload = $requestPayload;
        $this->responsePayload = $responsePayload;
        $this->orderId = $orderId;
        $this->createdAt = new \DateTimeImmutable();
    }
}
