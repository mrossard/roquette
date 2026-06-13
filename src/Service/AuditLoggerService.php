<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Enum\AuditAction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class AuditLoggerService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
    ) {}

    public function log(AuditAction $action, ?User $performedBy = null, array $details = []): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $ipAddress = $request?->getClientIp();

        $log = new AuditLog();
        $log->setAction($action);
        $log->setPerformedBy($performedBy);
        $log->setDetails($details);
        $log->setIpAddress($ipAddress);

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}
