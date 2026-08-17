<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Service\MessageFeedContextService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class MessageFeedContextServiceTest extends TestCase
{
    #[Test]
    public function buildFeedContextReturnsAggregatedMetadata(): void
    {
        $messageRepository = $this->createMock(MessageRepository::class);
        $channelRepository = $this->createMock(ChannelRepository::class);

        $channel = new Channel();
        $subchannel = new Channel();

        $msg1 = $this->createMock(Message::class);
        $msg1->method('getId')->willReturn(10);
        $msg2 = $this->createMock(Message::class);
        $msg2->method('getId')->willReturn(20);
        $msgWithoutId = $this->createMock(Message::class);
        $msgWithoutId->method('getId')->willReturn(null);

        $messages = [$msg1, $msg2, $msgWithoutId];

        $messageRepository
            ->expects($this->once())
            ->method('findReplyCounts')
            ->with([10, 20])
            ->willReturn([10 => 3, 20 => 0]);

        $channelRepository
            ->expects($this->once())
            ->method('findSubchannelsByChannel')
            ->with($channel)
            ->willReturn([10 => $subchannel]);

        $service = new MessageFeedContextService($messageRepository, $channelRepository);
        $context = $service->buildFeedContext($channel, $messages);

        $this->assertSame([10 => 3, 20 => 0], $context['replyCounts']);
        $this->assertSame([10 => $subchannel], $context['subchannelByParentMessageId']);
        $this->assertArrayNotHasKey('savedMessageIds', $context);
    }

    #[Test]
    public function buildFeedContextIncludesSavedMessageIdsWhenUserProvided(): void
    {
        $messageRepository = $this->createMock(MessageRepository::class);
        $channelRepository = $this->createMock(ChannelRepository::class);

        $channel = new Channel();
        $user = new User();

        $messageRepository->method('findReplyCounts')->willReturn([]);
        $channelRepository->method('findSubchannelsByChannel')->willReturn([]);

        $messageRepository
            ->expects($this->once())
            ->method('findSavedMessageIdsForUser')
            ->with($user)
            ->willReturn([10, 15]);

        $service = new MessageFeedContextService($messageRepository, $channelRepository);
        $context = $service->buildFeedContext($channel, [], $user);

        $this->assertArrayHasKey('savedMessageIds', $context);
        $this->assertSame([10, 15], $context['savedMessageIds']);
    }
}
