<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\OAuth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\OAuth\OAuthUserManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

#[AllowMockObjectsWithoutExpectations]
class OAuthUserManagerTest extends TestCase
{
    private UserRepository $userRepository;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    private LoggerInterface $logger;
    private OAuthUserManager $manager;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->manager = new OAuthUserManager(
            $this->userRepository,
            $this->entityManager,
            $this->passwordHasher,
            $this->logger,
        );
    }

    public function testFindOrCreateUserReturnsExistingUserByOauthId(): void
    {
        $user = new User();
        $user->setUsername('alice');
        $user->setOauthId('oauth_123');

        $this->userRepository
            ->expects(static::once())
            ->method('findOneBy')
            ->with(['oauthId' => 'oauth_123', 'oauthProvider' => 'generic'])
            ->willReturn($user);

        $result = $this->manager->findOrCreateUser('oauth_123', 'alice', 'Alice', null);
        static::assertSame($user, $result);
    }

    public function testFindOrCreateUserSyncsEmailWhenMissingOnExistingOAuthUser(): void
    {
        $user = new User();
        $user->setUsername('alice');
        $user->setOauthId('oauth_123');

        $this->userRepository->method('findOneBy')->willReturn($user);

        $this->entityManager->expects(static::once())->method('flush');

        $result = $this->manager->findOrCreateUser('oauth_123', 'alice', 'Alice', 'alice@example.com');
        static::assertSame('alice@example.com', $result->getEmail());
        static::assertNotNull($result->getEmailVerifiedAt());
    }

    public function testFindOrCreateUserThrowsWhenOAuthUserIsBanned(): void
    {
        $user = new User();
        $user->setUsername('alice');
        $user->setOauthId('oauth_123');
        $user->setBannedAt(new \DateTimeImmutable());

        $this->userRepository->method('findOneBy')->willReturn($user);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Votre compte a été suspendu. Veuillez contacter un administrateur.');

        $this->manager->findOrCreateUser('oauth_123', 'alice', 'Alice', null);
    }

    public function testFindOrCreateUserLinksExistingUserByUsername(): void
    {
        $existingUser = new User();
        $existingUser->setUsername('bob');
        $existingUser->setOauthId(null);

        $this->userRepository
            ->method('findOneBy')
            ->willReturnCallback(static function (array $criteria) use ($existingUser) {
                if (($criteria['oauthId'] ?? null) === 'oauth_456') {
                    return null;
                }
                if (($criteria['username'] ?? null) === 'bob') {
                    return $existingUser;
                }
                return null;
            });

        $this->entityManager->expects(static::once())->method('flush');

        $result = $this->manager->findOrCreateUser('oauth_456', 'bob', 'Bob', 'bob@example.com');
        static::assertSame($existingUser, $result);
        static::assertSame('oauth_456', $result->getOauthId());
        static::assertSame('generic', $result->getOauthProvider());
        static::assertSame('bob@example.com', $result->getEmail());
    }

    public function testFindOrCreateUserThrowsWhenLinkingToDifferentOauthId(): void
    {
        $existingUser = new User();
        $existingUser->setUsername('bob');
        $existingUser->setOauthId('other_oauth_id');

        $this->userRepository
            ->method('findOneBy')
            ->willReturnCallback(static function (array $criteria) use ($existingUser) {
                if (($criteria['oauthId'] ?? null) === 'oauth_456') {
                    return null;
                }
                if (($criteria['username'] ?? null) === 'bob') {
                    return $existingUser;
                }
                return null;
            });

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Ce nom d\'utilisateur est déjà lié à un autre compte OAuth.');

        $this->manager->findOrCreateUser('oauth_456', 'bob', 'Bob', null);
    }

    public function testFindOrCreateUserRegistersNewUser(): void
    {
        $this->userRepository->method('findOneBy')->willReturn(null);

        $this->passwordHasher->method('hashPassword')->willReturn('hashed_password');
        $this->entityManager->expects(static::once())->method('persist');
        $this->entityManager->expects(static::once())->method('flush');

        $result = $this->manager->findOrCreateUser('oauth_new', 'charlie', 'Charlie', 'charlie@example.com');

        static::assertSame('charlie', $result->getUsername());
        static::assertSame('Charlie', $result->getDisplayName());
        static::assertSame('oauth_new', $result->getOauthId());
        static::assertSame('generic', $result->getOauthProvider());
        static::assertSame('charlie@example.com', $result->getEmail());
        static::assertSame(['ROLE_USER'], $result->getRoles());
    }
}
