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
        if ($user === null) {
            return false;
        }

        return strcasecmp($user->getUsername(), User::ROBOT_USERNAME) === 0;
    }
}
