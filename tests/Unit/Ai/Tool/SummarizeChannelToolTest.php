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
use PHPUnit\Framework\TestCase;

final class SummarizeChannelToolTest extends TestCase
{
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
        $channelResolver = new ChannelResolver($channelRepo, $workspaceRepo);

        $msg = new Message();
        $msg->setContent('Hello World');
        $msg->setCreatedAt(new \DateTimeImmutable());
        $messageRepo->expects($this->once())->method('findLatestInChannel')->with($channel, 50)->willReturn([$msg]);

        $tool = new SummarizeChannelTool($userRepo, $messageRepo, $channelResolver);
        $result = $tool->execute(['channelSlug' => 'general'], 1);

        $this->assertArrayHasKey('messages', $result);
        $this->assertSame('general', $result['channelName']);
    }
}
