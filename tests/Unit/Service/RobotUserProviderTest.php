<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\RobotUserProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class RobotUserProviderTest extends TestCase
{
    public function testGetRobotUserReturnsUserFromRepository(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $robotUser = new User();
        $robotUser->setUsername(User::ROBOT_USERNAME);

        $userRepository
            ->expects(static::exactly(2))
            ->method('findOneBy')
            ->with(['username' => User::ROBOT_USERNAME])
            ->willReturn($robotUser);

        $provider = new RobotUserProvider($userRepository);

        static::assertSame($robotUser, $provider->getRobotUser());
        // No caching: each call re-queries to always return a managed entity
        static::assertSame($robotUser, $provider->getRobotUser());
    }

    public function testIsRobotUser(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $provider = new RobotUserProvider($userRepository);

        $robot = new User();
        $robot->setUsername('ROBOT-ROQUETTE');

        $regular = new User();
        $regular->setUsername('alice');

        static::assertTrue($provider->isRobotUser($robot));
        static::assertFalse($provider->isRobotUser($regular));
        static::assertFalse($provider->isRobotUser(null));
    }
}
