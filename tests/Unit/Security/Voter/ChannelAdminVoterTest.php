<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\Channel;
use App\Entity\User;
use App\Security\Voter\ChannelAdminVoter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

#[AllowMockObjectsWithoutExpectations]
class ChannelAdminVoterTest extends TestCase
{
    private Security $security;
    private ChannelAdminVoter $voter;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->voter = new ChannelAdminVoter($this->security);
    }

    private function setUserEntityId(User $user, int $id): void
    {
        $ref = new \ReflectionClass(User::class);
        $prop = $ref->getProperty('id');
        $prop->setValue($user, $id);
    }

    public function testGrantedForRoleAdmin(): void
    {
        $user = new User();
        $this->setUserEntityId($user, 1);

        $channel = new Channel();

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->security->expects(static::once())->method('isGranted')->with('ROLE_ADMIN')->willReturn(true);

        $result = $this->voter->vote($token, $channel, [ChannelAdminVoter::EDIT]);
        static::assertSame(ChannelAdminVoter::ACCESS_GRANTED, $result);
    }

    public function testGrantedForChannelAdministrator(): void
    {
        $user = new User();
        $this->setUserEntityId($user, 1);
        $user->setUsername('creator_user');

        $channel = new Channel();
        $channel->setCreator($user);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->security->expects(static::once())->method('isGranted')->with('ROLE_ADMIN')->willReturn(false);

        $result = $this->voter->vote($token, $channel, [ChannelAdminVoter::EDIT]);
        static::assertSame(ChannelAdminVoter::ACCESS_GRANTED, $result);
    }

    public function testDeniedForRegularMember(): void
    {
        $user = new User();
        $this->setUserEntityId($user, 2);
        $user->setUsername('regular_user');

        $creator = new User();
        $this->setUserEntityId($creator, 1);
        $creator->setUsername('creator');

        $channel = new Channel();
        $channel->setCreator($creator);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->security->expects(static::once())->method('isGranted')->with('ROLE_ADMIN')->willReturn(false);

        $result = $this->voter->vote($token, $channel, [ChannelAdminVoter::EDIT]);
        static::assertSame(ChannelAdminVoter::ACCESS_DENIED, $result);
    }

    public function testGrantedForInviteAttribute(): void
    {
        $user = new User();
        $this->setUserEntityId($user, 1);

        $channel = new Channel();
        $channel->setCreator($user);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->security->expects(static::once())->method('isGranted')->with('ROLE_ADMIN')->willReturn(false);

        $result = $this->voter->vote($token, $channel, [ChannelAdminVoter::INVITE]);
        static::assertSame(ChannelAdminVoter::ACCESS_GRANTED, $result);
    }

    public function testGrantedForWorkspaceCreatorOnChannel(): void
    {
        $user = new User();
        $this->setUserEntityId($user, 1);

        $workspace = new \App\Entity\Workspace();
        $this->setUserEntityId($user, 1);
        $workspace->setCreator($user);

        $channel = new Channel();
        $channel->setWorkspace($workspace);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->security->expects(static::once())->method('isGranted')->with('ROLE_ADMIN')->willReturn(false);

        $result = $this->voter->vote($token, $channel, [ChannelAdminVoter::INVITE]);
        static::assertSame(ChannelAdminVoter::ACCESS_GRANTED, $result);
    }
}
