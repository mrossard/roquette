<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Tool;

use App\Ai\ChannelResolver;
use App\Ai\Tool\SummarizeChannelTool;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\ChannelAccessService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class SummarizeChannelToolTest extends TestCase
{
    private function buildTool(
        UserRepository $userRepo,
        MessageRepository $messageRepo,
        \App\Repository\ChannelRepository $channelRepo,
        \App\Repository\WorkspaceRepository $workspaceRepo,
        ?ChannelAccessService $accessService = null,
    ): SummarizeChannelTool {
        if ($accessService === null) {
            $accessService = $this->createMock(ChannelAccessService::class);
            $accessService->method('canUserAccess')->willReturn(true);
        }

        return new SummarizeChannelTool(
            $userRepo,
            $messageRepo,
            new ChannelResolver($channelRepo, $workspaceRepo),
            $accessService,
        );
    }

    public function testSummarizeChannelReturnsMessages(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $messageRepo = $this->createMock(MessageRepository::class);
        $channelRepo = $this->createMock(\App\Repository\ChannelRepository::class);
        $workspaceRepo = $this->createMock(\App\Repository\WorkspaceRepository::class);

        $user = new User();
        $userRepo->expects($this->once())->method('find')->with(1)->willReturn($user);

        $channel = new Channel();
        $channel->setName('general');
        $channel->setSlug('general');

        $channelRepo->expects($this->once())->method('findOneBy')->with(['slug' => 'general'])->willReturn($channel);

        $msg = new Message();
        $msg->setContent('Hello World');
        $msg->setCreatedAt(new \DateTimeImmutable());
        $messageRepo->expects($this->once())->method('findLatestInChannel')->with($channel, 50)->willReturn([$msg]);

        $tool = $this->buildTool($userRepo, $messageRepo, $channelRepo, $workspaceRepo);
        $result = $tool->execute(['channelSlug' => 'general'], 1);

        $this->assertArrayHasKey('messages', $result);
        $this->assertSame('general', $result['channelName']);
    }

    public function testSummarizeDeniedWhenUserHasNoAccess(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $messageRepo = $this->createMock(MessageRepository::class);
        $channelRepo = $this->createMock(\App\Repository\ChannelRepository::class);
        $workspaceRepo = $this->createMock(\App\Repository\WorkspaceRepository::class);

        $user = new User();
        $userRepo->expects($this->once())->method('find')->with(1)->willReturn($user);

        $channel = new Channel();
        $channel->setName('private');
        $channel->setSlug('private');
        $channelRepo->method('findOneBy')->willReturn($channel);

        $accessService = $this->createMock(ChannelAccessService::class);
        $accessService->method('canUserAccess')->willReturn(false);

        $messageRepo->expects($this->never())->method('findLatestInChannel');

        $tool = $this->buildTool($userRepo, $messageRepo, $channelRepo, $workspaceRepo, $accessService);
        $result = $tool->execute(['channelSlug' => 'private'], 1);

        $this->assertSame("Vous n'avez pas accès au canal 'private'.", $result['error'] ?? null);
    }
}
