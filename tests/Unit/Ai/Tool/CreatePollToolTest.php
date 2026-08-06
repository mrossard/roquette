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
use App\Service\MercurePublisher;
use App\Service\MessageFormatter;
use App\Service\MessageRenderer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

class CreatePollToolTest extends TestCase
{
    private function buildTool(
        ChannelRepository $channelRepo,
        WorkspaceRepository $workspaceRepo,
        EntityManagerInterface $em,
    ): CreatePollTool {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->any())->method('findOneBy')->with(['username' => 'robot-roquette'])->willReturn($this->makeUser());

        $mercurePublisher = $this->createMock(MercurePublisher::class);
        $messageFormatter = $this->createMock(MessageFormatter::class);
        $messageFormatter->expects($this->any())->method('format')->willReturn('formatted text');
        $twig = $this->createMock(Environment::class);
        $messageRenderer = $this->createMock(MessageRenderer::class);
        $messageRenderer->expects($this->any())->method('renderFeedItem')->willReturn('<div>Poll HTML</div>');

        return new CreatePollTool(
            $em,
            $userRepo,
            $mercurePublisher,
            $messageFormatter,
            $twig,
            $messageRenderer,
            new ChannelResolver($channelRepo, $workspaceRepo),
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
        $channelRepo->expects($this->any())->method('findOneBy')->with(['slug' => 'general'])->willReturn($channel);

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
        $channelRepo->expects($this->any())->method('findOneBy')->with(['slug' => 'test'])->willReturn($globalChannel);

        $workspace = new Workspace();
        $workspace->setName('Workspace A');
        $workspace->setSlug('ws-a');
        $workspaceChannel = $this->makeChannel('test', 'sc-test-258258');
        $workspace->addChannel($workspaceChannel);

        $workspaceRepo = $this->createMock(WorkspaceRepository::class);
        $workspaceRepo->expects($this->any())->method('find')->with(46)->willReturn($workspace);

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
        $channelRepo->expects($this->any())->method('findOneBy')->with(['slug' => 'test'])->willReturn($globalChannel);

        $workspace = new Workspace();
        $workspace->setName('Workspace A');
        $workspace->setSlug('ws-a');

        $workspaceRepo = $this->createMock(WorkspaceRepository::class);
        $workspaceRepo->expects($this->any())->method('find')->with(46)->willReturn($workspace);

        $tool = $this->buildTool($channelRepo, $workspaceRepo, $em);

        $result = $tool->__invoke('test', 'Choix ?', ['A', 'B'], false, null, 46);

        static::assertStringContainsString('canal #test', $result);
    }
}
