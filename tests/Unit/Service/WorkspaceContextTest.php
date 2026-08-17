<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Workspace;
use App\Repository\WorkspaceRepository;
use App\Service\WorkspaceContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

#[AllowMockObjectsWithoutExpectations]
class WorkspaceContextTest extends TestCase
{
    private RequestStack $requestStack;
    private WorkspaceRepository $workspaceRepository;
    private SessionInterface $session;
    private WorkspaceContext $context;

    protected function setUp(): void
    {
        $this->requestStack = new RequestStack();
        $this->workspaceRepository = $this->createMock(WorkspaceRepository::class);
        $this->session = $this->createMock(SessionInterface::class);

        $request = new Request();
        $request->setSession($this->session);
        $this->requestStack->push($request);

        $this->context = new WorkspaceContext($this->requestStack, $this->workspaceRepository);
    }

    #[Test]
    public function getCurrentWorkspaceReturnsWorkspaceFromSessionId(): void
    {
        $workspace = $this->createMock(Workspace::class);
        $this->session->method('get')->willReturn(42);
        $this->workspaceRepository->method('find')->willReturn($workspace);

        static::assertSame($workspace, $this->context->getCurrentWorkspace());
    }

    #[Test]
    public function getCurrentWorkspaceFallsBackToPublicWorkspaceWhenSessionEmpty(): void
    {
        $publicWorkspace = $this->createMock(Workspace::class);
        $publicWorkspace->method('getId')->willReturn(1);

        $this->session->method('get')->willReturn(null);
        $this->workspaceRepository->method('findPublicWorkspace')->willReturn($publicWorkspace);
        $this->session->expects(self::once())->method('set')->with(WorkspaceContext::SESSION_KEY, 1);

        static::assertSame($publicWorkspace, $this->context->getCurrentWorkspace());
    }

    #[Test]
    public function getCurrentWorkspaceReturnsNullWhenNoFallback(): void
    {
        $this->session->method('get')->willReturn(null);
        $this->workspaceRepository->expects(self::never())->method('findPublicWorkspace');

        static::assertNull($this->context->getCurrentWorkspace(fallbackToPublic: false));
    }

    #[Test]
    public function getCurrentWorkspaceHandlesMissingSessionGracefully(): void
    {
        $emptyStack = new RequestStack();
        $contextWithoutSession = new WorkspaceContext($emptyStack, $this->workspaceRepository);

        $publicWorkspace = $this->createMock(Workspace::class);
        $this->workspaceRepository->method('findPublicWorkspace')->willReturn($publicWorkspace);

        static::assertSame($publicWorkspace, $contextWithoutSession->getCurrentWorkspace());
    }

    #[Test]
    public function getCurrentWorkspaceIdReturnsDirectInteger(): void
    {
        $this->session->method('get')->willReturn(10);

        static::assertSame(10, $this->context->getCurrentWorkspaceId());
    }

    #[Test]
    public function getCurrentWorkspaceIdFallsBackWhenMissing(): void
    {
        $publicWorkspace = $this->createMock(Workspace::class);
        $publicWorkspace->method('getId')->willReturn(7);

        $this->session->method('get')->willReturn(null);
        $this->workspaceRepository->method('findPublicWorkspace')->willReturn($publicWorkspace);

        static::assertSame(7, $this->context->getCurrentWorkspaceId());
    }

    #[Test]
    public function setCurrentWorkspaceSetsIdInSession(): void
    {
        $workspace = $this->createMock(Workspace::class);
        $workspace->method('getId')->willReturn(99);

        $this->session->expects(self::once())->method('set')->with(WorkspaceContext::SESSION_KEY, 99);

        $this->context->setCurrentWorkspace($workspace);
    }

    #[Test]
    public function setCurrentWorkspaceNullRemovesSessionKey(): void
    {
        $this->session->expects(self::once())->method('remove')->with(WorkspaceContext::SESSION_KEY);

        $this->context->setCurrentWorkspace(null);
    }

    #[Test]
    public function syncFromChannelSetsWorkspaceWhenPresent(): void
    {
        $workspace = $this->createMock(Workspace::class);
        $workspace->method('getId')->willReturn(55);

        $channel = $this->createMock(Channel::class);
        $channel->method('getWorkspace')->willReturn($workspace);

        $this->session->expects(self::once())->method('set')->with(WorkspaceContext::SESSION_KEY, 55);

        static::assertSame($workspace, $this->context->syncFromChannel($channel));
    }

    #[Test]
    public function syncFromChannelResolvesCurrentWhenChannelHasNoWorkspace(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getWorkspace')->willReturn(null);

        $publicWorkspace = $this->createMock(Workspace::class);
        $publicWorkspace->method('getId')->willReturn(1);

        $this->session->method('get')->willReturn(null);
        $this->workspaceRepository->method('findPublicWorkspace')->willReturn($publicWorkspace);

        static::assertSame($publicWorkspace, $this->context->syncFromChannel($channel));
    }
}
