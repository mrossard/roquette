<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\BannedUserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

class BannedUserCheckerTest extends TestCase
{
    private BannedUserChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new BannedUserChecker();
    }

    public function testCheckPreAuthAllowsNormalActiveUser(): void
    {
        $user = $this->createStub(User::class);
        $user->method('isBanned')->willReturn(false);
        $user->method('getUsername')->willReturn('normal_user');

        $this->checker->checkPreAuth($user);
        $this->expectNotToPerformAssertions();
    }

    public function testCheckPreAuthThrowsExceptionForBannedUser(): void
    {
        $user = $this->createStub(User::class);
        $user->method('isBanned')->willReturn(true);
        $user->method('getUsername')->willReturn('banned_user');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Votre compte a été suspendu. Veuillez contacter un administrateur.');

        $this->checker->checkPreAuth($user);
    }

    public function testCheckPreAuthThrowsExceptionForRobotUser(): void
    {
        $user = $this->createStub(User::class);
        $user->method('isBanned')->willReturn(false);
        $user->method('getUsername')->willReturn('robot-roquette');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Connexion impossible avec un compte système.');

        $this->checker->checkPreAuth($user);
    }
}
