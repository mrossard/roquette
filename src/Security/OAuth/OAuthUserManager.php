<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\User;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

class OAuthUserManager
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger,
    ) {}

    public function findOrCreateUser(string $oauthId, string $username, string $displayName, ?string $email): User
    {
        // 1. Search by OAuth ID and provider
        $user = $this->userRepository->findOneBy([
            'oauthId' => $oauthId,
            'oauthProvider' => 'generic',
        ]);

        if ($user !== null) {
            $this->assertUserNotBanned($user);
            $this->syncUserEmailIfMissing($user, $email);
            $this->logger->debug(sprintf(
                'User "%s" authenticated via OAuth2 with provider ID "%s".',
                $user->getUsername(),
                $oauthId,
            ));

            return $user;
        }

        // 2. Search by username to link account
        $existingUserByUsername = $this->userRepository->findOneBy(['username' => $username]);
        if ($existingUserByUsername !== null) {
            return $this->linkExistingUser($existingUserByUsername, $oauthId, $username, $email);
        }

        // 3. Create a brand new user
        return $this->registerNewOAuthUser($oauthId, $username, $displayName, $email);
    }

    private function assertUserNotBanned(User $user): void
    {
        if ($user->isBanned()) {
            throw new CustomUserMessageAuthenticationException(
                'Votre compte a été suspendu. Veuillez contacter un administrateur.',
            );
        }
    }

    private function syncUserEmailIfMissing(User $user, ?string $email): void
    {
        if ($email !== null && $user->getEmail() === null) {
            $user->setEmail($email);
            $user->setEmailVerifiedAt(new DateTimeImmutable());
            $this->entityManager->flush();
            $this->logger->info(sprintf(
                'Filled missing email "%s" for OAuth user "%s" (ID: %d).',
                $email,
                $user->getUsername(),
                $user->getId() ?? 0,
            ));
        }
    }

    private function linkExistingUser(User $existingUser, string $oauthId, string $username, ?string $email): User
    {
        if ($existingUser->getOauthId() !== null && $existingUser->getOauthId() !== $oauthId) {
            $this->logger->warning(sprintf(
                'Refused linking OAuth account for username "%s": existing OAuth ID "%s" does not match incoming "%s".',
                $username,
                $existingUser->getOauthId() ?? '',
                $oauthId,
            ));
            throw new CustomUserMessageAuthenticationException(
                'Ce nom d\'utilisateur est déjà lié à un autre compte OAuth.',
            );
        }

        $this->assertUserNotBanned($existingUser);

        if ($email !== null && $existingUser->getEmail() === null) {
            $existingUser->setEmail($email);
        }

        $existingUser->setOauthId($oauthId);
        $existingUser->setOauthProvider('generic');
        $this->entityManager->flush();
        $this->logger->info(sprintf(
            'Linked existing user "%s" (ID: %d) with OAuth2 ID "%s".',
            $username,
            $existingUser->getId() ?? 0,
            $oauthId,
        ));

        return $existingUser;
    }

    private function registerNewOAuthUser(string $oauthId, string $username, string $displayName, ?string $email): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setDisplayName($displayName);
        $user->setOauthId($oauthId);
        $user->setOauthProvider('generic');

        if ($email !== null) {
            $user->setEmail($email);
            $user->setEmailVerifiedAt(new DateTimeImmutable());
        }

        $randomPassword = bin2hex(random_bytes(16));
        $user->setPassword($this->passwordHasher->hashPassword($user, $randomPassword));
        $user->setRoles(['ROLE_USER']);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->logger->info(sprintf(
            'Created new user "%s" (ID: %d) via OAuth2 registration with OAuth2 ID "%s".',
            $username,
            $user->getId() ?? 0,
            $oauthId,
        ));

        return $user;
    }
}
