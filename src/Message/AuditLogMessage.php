<?php

declare(strict_types=1);

namespace App\Message;

use App\Enum\AuditAction;

class AuditLogMessage
{
    public function __construct(
        private readonly AuditAction $action,
        private readonly ?int $performedById,
        private readonly array $details,
        private readonly ?string $ipAddress,
        private readonly \DateTimeImmutable $createdAt,
    ) {}

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    public function getPerformedById(): ?int
    {
        return $this->performedById;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
