<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;

class RobotUserProvider
{
    private ?User $robotUser = null;

    public function __construct(
        private readonly UserRepository $userRepository,
    ) {}

    public function getRobotUser(): ?User
    {
        if ($this->robotUser === null) {
            $this->robotUser = $this->userRepository->findOneBy(['username' => User::ROBOT_USERNAME]);
        }

        return $this->robotUser;
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
