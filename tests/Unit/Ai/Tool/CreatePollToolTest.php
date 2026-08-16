<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Tool;

use App\Ai\ChannelResolver;
use App\Ai\Tool\CreatePollTool;
use App\Entity\Channel;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\ChannelRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use App\Service\ChannelAccessService;
use App\Service\MercurePublisher;
use App\Service\MessageFormatter;
use App\Service\MessageRenderer;
use App\Service\RobotUserProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

#[AllowMockObjectsWithoutExpectations]
class CreatePollToolTest extends TestCase
{
    private function buildTool(
        ChannelRepository $channelRepo,
        WorkspaceRepository $workspaceRepo,
        EntityManagerInterface $em,
        ?ChannelAccessService $accessService = null,
    ): CreatePollTool {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturn($this->makeUser());

        $mercurePublisher = $this->createMock(MercurePublisher::class);
        $messageFormatter = $this->createMock(MessageFormatter::class);
        $messageFormatter->method('format')->willReturn('formatted text');
        $twig = $this->createMock(Environment::class);
        $messageRenderer = $this->createMock(MessageRenderer::class);
        $messageRenderer->method('renderFeedItem')->willReturn('<div>Poll HTML</div>');

        if ($accessService === null) {
            $accessService = $this->createMock(ChannelAccessService::class);
            $accessService->method('canUserAccess')->willReturn(true);
        }

        return new CreatePollTool(
            $em,
            $userRepo,
            new RobotUserProvider($userRepo),
            $mercurePublisher,
            $messageFormatter,
            $twig,
            $messageRenderer,
            new ChannelResolver($channelRepo, $workspaceRepo),
            $accessService ?? $this->createMock(ChannelAccessService::class),
        );
    }

    private function makeChannel(string $name, string $slug): Channel
    {
        $channel = new Channel();
        $channel->setName($name);
        $channel->setSlug($slug);

        return $channel;
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->setUsername('robot-roquette');

        return $user;
    }

    public function testCreatePollSuccessfully(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->atLeast(2))->method('persist');
        $em->expects($this->once())->method('flush');

        $channel = $this->makeChannel('general', 'general');
        $channelRepo = $this->createMock(ChannelRepository::class);
        $channelRepo->method('findOneBy')->willReturn($channel);

        $workspaceRepo = $this->createMock(WorkspaceRepository::class);
        $tool = $this->buildTool($channelRepo, $workspaceRepo, $em);

        $result = $tool->__invoke('general', 'Choix resto ?', ['Option A', 'Option B']);

        static::assertStringContainsString('a été publié dans le canal #general', $result);
    }

    public function testWorkspaceNameWinsOverGlobalSlug(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->atLeast(2))->method('persist');
        $em->expects($this->once())->method('flush');

        $globalChannel = $this->makeChannel('général', 'test');
        $channelRepo = $this->createMock(ChannelRepository::class);
        $channelRepo->method('findOneBy')->willReturn($globalChannel);

        $workspace = new Workspace();
        $workspace->setName('Workspace A');
        $workspace->setSlug('ws-a');
        $workspaceChannel = $this->makeChannel('test', 'sc-test-258258');
        $workspace->addChannel($workspaceChannel);

        $workspaceRepo = $this->createMock(WorkspaceRepository::class);
        $workspaceRepo->method('find')->willReturn($workspace);

        $tool = $this->buildTool($channelRepo, $workspaceRepo, $em);

        $result = $tool->__invoke('test', 'Choix ?', ['A', 'B'], false, null, 46);

        static::assertStringContainsString('canal #test', $result);
    }

    public function testWorkspaceSlugFallsBackToGlobalWhenWorkspaceChannelNotFound(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->atLeast(2))->method('persist');
        $em->expects($this->once())->method('flush');

        $globalChannel = $this->makeChannel('test', 'test');
        $channelRepo = $this->createMock(ChannelRepository::class);
        $channelRepo->method('findOneBy')->willReturn($globalChannel);

        $workspace = new Workspace();
        $workspace->setName('Workspace A');
        $workspace->setSlug('ws-a');

        $workspaceRepo = $this->createMock(WorkspaceRepository::class);
        $workspaceRepo->method('find')->willReturn($workspace);

        $tool = $this->buildTool($channelRepo, $workspaceRepo, $em);

        $result = $tool->__invoke('test', 'Choix ?', ['A', 'B'], false, null, 46);

        static::assertStringContainsString('canal #test', $result);
    }

    public function testCreatePollDeniedWhenUserHasNoAccess(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $channel = $this->makeChannel('private', 'private');
        $channelRepo = $this->createMock(ChannelRepository::class);
        $channelRepo->method('findOneBy')->willReturn($channel);

        $workspaceRepo = $this->createMock(WorkspaceRepository::class);

        $accessService = $this->createMock(ChannelAccessService::class);
        $accessService->method('canUserAccess')->willReturn(false);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('find')->willReturn($this->makeUser());

        $tool = new CreatePollTool(
            $em,
            $userRepo,
            new RobotUserProvider($userRepo),
            $this->createMock(MercurePublisher::class),
            $this->createMock(MessageFormatter::class),
            $this->createMock(Environment::class),
            $this->createMock(MessageRenderer::class),
            new ChannelResolver($channelRepo, $workspaceRepo),
            $accessService,
        );

        $result = $tool->__invoke('private', 'Choix ?', ['A', 'B'], false, 7, null);

        static::assertStringContainsString('pas accès', $result);
    }
}
