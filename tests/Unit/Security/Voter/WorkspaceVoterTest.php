<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\User;
use App\Entity\Workspace;
use App\Security\Voter\WorkspaceVoter;
use App\Service\WorkspaceManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
class WorkspaceVoterTest extends TestCase
{
    private WorkspaceManager $workspaceManager;
    private Security $security;
    private WorkspaceVoter $voter;

    protected function setUp(): void
    {
        $this->workspaceManager = $this->createMock(WorkspaceManager::class);
        $this->security = $this->createMock(Security::class);
        $this->voter = new WorkspaceVoter($this->workspaceManager, $this->security);
    }

    #[Test]
    public function voteDeniesWhenUserNotLogged(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $workspace = new Workspace();
        $result = $this->voter->vote($token, $workspace, ['VIEW']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function voteGrantsViewWhenUserIsMember(): void
    {
        $user = new User();
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $workspace = new Workspace();
        $this->workspaceManager->expects($this->once())->method('isUserMember')->with($workspace, $user)->willReturn(true);

        $result = $this->voter->vote($token, $workspace, ['VIEW']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function voteDeniesViewWhenUserIsNotMember(): void
    {
        $user = new User();
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $workspace = new Workspace();
        $this->workspaceManager->expects($this->once())->method('isUserMember')->with($workspace, $user)->willReturn(false);

        $result = $this->voter->vote($token, $workspace, ['VIEW']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function voteGrantsEditWhenUserIsAdmin(): void
    {
        $user = new User();
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $workspace = new Workspace();
        $this->security->expects($this->once())->method('isGranted')->with('ROLE_ADMIN')->willReturn(true);

        $result = $this->voter->vote($token, $workspace, ['EDIT']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function voteGrantsEditWhenUserIsCreator(): void
    {
        $user = new User();
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $workspace = new Workspace();
        $workspace->setCreator($user);
        $this->security->expects($this->once())->method('isGranted')->with('ROLE_ADMIN')->willReturn(false);

        $result = $this->voter->vote($token, $workspace, ['EDIT']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function voteDeniesEditWhenUserIsNotCreatorNorAdmin(): void
    {
        $user = new User();
        $otherUser = new User();
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $workspace = new Workspace();
        $workspace->setCreator($otherUser);
        $this->security->expects($this->once())->method('isGranted')->with('ROLE_ADMIN')->willReturn(false);

        $result = $this->voter->vote($token, $workspace, ['EDIT']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}
