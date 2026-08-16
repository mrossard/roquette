<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;

class RobotUserProvider
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {}

    /**
     * Always re-queries the repository: the EntityManager is cleared between
     * Messenger messages, so a cached entity would be detached and cause
     * persist errors (e.g. when used as a Message author).
     */
    public function getRobotUser(): ?User
    {
        return $this->userRepository->findOneBy(['username' => User::ROBOT_USERNAME]);
    }

    public function isRobotUser(?User $user): bool
    {
        return $user !== null && $this->isRobotUsername($user->getUsername() ?? '');
    }

    public function isRobotUsername(string $username): bool
    {
        return strcasecmp($username, User::ROBOT_USERNAME) === 0;
    }

    public function getDmChannelSlug(User $user): string
    {
        return 'dm-' . User::ROBOT_USERNAME . '-' . $user->getSlug();
    }

    public function isRobotDmChannel(string $channelSlug): bool
    {
        return str_starts_with($channelSlug, 'dm-' . User::ROBOT_USERNAME . '-');
    }
}
