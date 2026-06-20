<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class BannedUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if ($user->isBanned()) {
            throw new CustomUserMessageAuthenticationException(
                'Votre compte a été suspendu. Veuillez contacter un administrateur.',
            );
        }

        if (strcasecmp($user->getUsername() ?? '', User::ROBOT_USERNAME) === 0) {
            throw new CustomUserMessageAuthenticationException(
                'Connexion impossible avec un compte système.',
            );
        }
    }

    public function checkPostAuth(UserInterface $user, #[\SensitiveParameter] ?TokenInterface $token = null): void {}
}
