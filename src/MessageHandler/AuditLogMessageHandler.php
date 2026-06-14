<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\AuditLog;
use App\Message\AuditLogMessage;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class AuditLogMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
    ) {}

    public function __invoke(AuditLogMessage $message): void
    {
        $performedBy = $message->getPerformedById()
            ? $this->userRepository->find($message->getPerformedById())
            : null;

        $log = new AuditLog();
        $log->setAction($message->getAction());
        $log->setPerformedBy($performedBy);
        $log->setDetails($message->getDetails());
        $log->setIpAddress($message->getIpAddress());
        $log->setCreatedAt($message->getCreatedAt());

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}
