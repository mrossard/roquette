<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Security\Voter\UserGroupVoter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
class UserGroupVoterTest extends TestCase
{
    private Security $security;
    private UserGroupVoter $voter;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->voter = new UserGroupVoter($this->security);
    }

    #[Test]
    public function voteDeniesWhenUserNotLogged(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $group = new UserGroup();
        $result = $this->voter->vote($token, $group, [UserGroupVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function voteAbstainsOnUnsupportedSubjectOrAttribute(): void
    {
        $user = new User();
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, new \stdClass(), [UserGroupVoter::VIEW]));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, new UserGroup(), ['UNSUPPORTED']));
    }

    #[Test]
    public function voteGrantsEverythingToRoleAdmin(): void
    {
        $user = new User();
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->security->method('isGranted')->willReturn(true);

        $group = new UserGroup();
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $group, [UserGroupVoter::VIEW]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $group, [UserGroupVoter::MANAGE]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $group, [UserGroupVoter::DELETE]));
    }

    #[Test]
    public function voteGrantsManageToGroupAdmin(): void
    {
        $user = new User();
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->security->method('isGranted')->willReturn(false);

        $group = new UserGroup();
        $group->addAdministrator($user);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $group, [UserGroupVoter::VIEW]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $group, [UserGroupVoter::MANAGE]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $group, [UserGroupVoter::DELETE]));
    }

    #[Test]
    public function voteGrantsViewToGroupMember(): void
    {
        $user = new User();
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->security->method('isGranted')->willReturn(false);

        $group = new UserGroup();
        $group->addMember($user);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $group, [UserGroupVoter::VIEW]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $group, [UserGroupVoter::MANAGE]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $group, [UserGroupVoter::DELETE]));
    }

    #[Test]
    public function voteDeniesNonMemberNonAdmin(): void
    {
        $user = new User();
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->security->method('isGranted')->willReturn(false);

        $group = new UserGroup();

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $group, [UserGroupVoter::VIEW]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $group, [UserGroupVoter::MANAGE]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $group, [UserGroupVoter::DELETE]));
    }
}
