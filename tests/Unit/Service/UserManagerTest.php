<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Enum\AuditAction;
use App\Service\AuditLoggerService;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class UserManagerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private AuditLoggerService $auditLogger;
    private LoggerInterface $logger;
    private UserManager $userManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createMock(AuditLoggerService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->userManager = new UserManager(
            $this->entityManager,
            $this->auditLogger,
            $this->logger,
        );
    }

    private function setUserProperties(User $user, int $id, string $username, bool $isAdmin = false): void
    {
        $ref = new \ReflectionClass($user);
        $idProp = $ref->getProperty('id');
        $idProp->setValue($user, $id);

        $user->setUsername($username);
        $user->setAdmin($isAdmin);
        $user->setRoles($isAdmin ? ['ROLE_ADMIN', 'ROLE_USER'] : ['ROLE_USER']);
    }

    public function testBanUserSuccess(): void
    {
        $targetUser = new User();
        $this->setUserProperties($targetUser, 2, 'target_user');

        $admin = new User();
        $this->setUserProperties($admin, 1, 'admin_user', true);

        $this->entityManager
            ->expects(static::once())
            ->method('flush');

        $this->auditLogger
            ->expects(static::once())
            ->method('log')
            ->with(
                AuditAction::USER_BAN,
                $admin,
                static::callback(static fn(array $context): bool => $context['banned_user_id'] === 2
                    && $context['username'] === 'target_user'
                    && $context['reason'] === 'Banni par un administrateur'),
            );

        $this->logger
            ->expects(static::once())
            ->method('info');

        $this->userManager->banUser($targetUser, $admin);

        static::assertTrue($targetUser->isBanned());
        static::assertSame('Banni par un administrateur', $targetUser->getBannedReason());
        static::assertNotNull($targetUser->getBannedAt());
    }

    public function testBanUserThrowsWhenAlreadyBanned(): void
    {
        $targetUser = new User();
        $this->setUserProperties($targetUser, 2, 'target_user');
        $targetUser->setBannedAt(new \DateTimeImmutable());

        $admin = new User();
        $this->setUserProperties($admin, 1, 'admin_user', true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('L\'utilisateur "target_user" est déjà banni.');

        $this->userManager->banUser($targetUser, $admin);
    }

    public function testBanUserThrowsWhenTargetIsAdmin(): void
    {
        $targetUser = new User();
        $this->setUserProperties($targetUser, 2, 'other_admin', true);

        $admin = new User();
        $this->setUserProperties($admin, 1, 'admin_user', true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Impossible de bannir un administrateur.');

        $this->userManager->banUser($targetUser, $admin);
    }

    public function testBanUserThrowsWhenTargetIsSelf(): void
    {
        $admin = new User();
        $this->setUserProperties($admin, 1, 'admin_user', true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Vous ne pouvez pas vous bannir vous-même.');

        $this->userManager->banUser($admin, $admin);
    }

    public function testUnbanUserSuccess(): void
    {
        $targetUser = new User();
        $this->setUserProperties($targetUser, 2, 'target_user');
        $targetUser->setBannedAt(new \DateTimeImmutable());
        $targetUser->setBannedReason('Prior ban');

        $admin = new User();
        $this->setUserProperties($admin, 1, 'admin_user', true);

        $this->entityManager
            ->expects(static::once())
            ->method('flush');

        $this->auditLogger
            ->expects(static::once())
            ->method('log')
            ->with(
                AuditAction::USER_UNBAN,
                $admin,
                static::callback(static fn(array $context): bool => $context['unbanned_user_id'] === 2
                    && $context['username'] === 'target_user'),
            );

        $this->logger
            ->expects(static::once())
            ->method('info');

        $this->userManager->unbanUser($targetUser, $admin);

        static::assertFalse($targetUser->isBanned());
        static::assertNull($targetUser->getBannedAt());
        static::assertNull($targetUser->getBannedReason());
    }

    public function testUnbanUserThrowsWhenNotBanned(): void
    {
        $targetUser = new User();
        $this->setUserProperties($targetUser, 2, 'target_user');

        $admin = new User();
        $this->setUserProperties($admin, 1, 'admin_user', true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('L\'utilisateur "target_user" n\'est pas banni.');

        $this->userManager->unbanUser($targetUser, $admin);
    }
}
