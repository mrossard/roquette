<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\AuditAction;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;

class UserManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLoggerService $auditLogger,
        private readonly LoggerInterface $logger,
    ) {}

    public function banUser(User $user, User $byAdmin, string $reason = 'Banni par un administrateur'): void
    {
        if ($user->isBanned()) {
            throw new LogicException(sprintf('L\'utilisateur "%s" est déjà banni.', $user->getUsername()));
        }

        if ($user->getId() !== null && $byAdmin->getId() !== null && $user->getId() === $byAdmin->getId()) {
            throw new InvalidArgumentException('Vous ne pouvez pas vous bannir vous-même.');
        }

        if ($user->isAdmin()) {
            throw new InvalidArgumentException('Impossible de bannir un administrateur.');
        }

        $user->setBannedAt(new \DateTimeImmutable());
        $user->setBannedReason($reason);
        $this->entityManager->flush();

        $this->auditLogger->log(AuditAction::USER_BAN, $byAdmin, [
            'banned_user_id' => $user->getId(),
            'username' => $user->getUsername(),
            'reason' => $reason,
        ]);

        $this->logger->info(sprintf(
            'User "%s" (ID: %d) has been banned by admin "%s" (ID: %d)',
            $user->getUsername(),
            $user->getId(),
            $byAdmin->getUsername(),
            $byAdmin->getId(),
        ));
    }

    public function unbanUser(User $user, User $byAdmin): void
    {
        if (!$user->isBanned()) {
            throw new LogicException(sprintf('L\'utilisateur "%s" n\'est pas banni.', $user->getUsername()));
        }

        $user->setBannedAt(null);
        $user->setBannedReason(null);
        $this->entityManager->flush();

        $this->auditLogger->log(AuditAction::USER_UNBAN, $byAdmin, [
            'unbanned_user_id' => $user->getId(),
            'username' => $user->getUsername(),
        ]);

        $this->logger->info(sprintf(
            'User "%s" (ID: %d) has been unbanned by admin "%s" (ID: %d)',
            $user->getUsername(),
            $user->getId(),
            $byAdmin->getUsername(),
            $byAdmin->getId(),
        ));
    }
}
