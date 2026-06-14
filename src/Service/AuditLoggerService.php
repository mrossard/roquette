<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\AuditAction;
use App\Message\AuditLogMessage;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;

class AuditLoggerService
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private RequestStack $requestStack,
    ) {}

    public function log(AuditAction $action, ?User $performedBy = null, array $details = []): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $ipAddress = $request?->getClientIp();

        $this->messageBus->dispatch(new AuditLogMessage(
            action: $action,
            performedById: $performedBy?->getId(),
            details: $details,
            ipAddress: $ipAddress,
            createdAt: new \DateTimeImmutable(),
        ));
    }
}
