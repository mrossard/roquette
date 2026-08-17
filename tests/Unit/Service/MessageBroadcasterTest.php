<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Service\MercurePublisher;
use App\Service\MessageBroadcaster;
use App\Service\MessageRenderer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class MessageBroadcasterTest extends TestCase
{
    private MessageRenderer&MockObject $messageRenderer;
    private MercurePublisher&MockObject $mercurePublisher;
    private MessageRepository&MockObject $messageRepository;
    private LoggerInterface&MockObject $logger;
    private MessageBroadcaster $broadcaster;

    protected function setUp(): void
    {
        $this->messageRenderer = $this->createMock(MessageRenderer::class);
        $this->mercurePublisher = $this->createMock(MercurePublisher::class);
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->broadcaster = new MessageBroadcaster(
            $this->messageRenderer,
            $this->mercurePublisher,
            $this->messageRepository,
            $this->logger,
        );
    }

    #[Test]
    public function broadcastMessageUpdateRendersAndPublishes(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getSlug')->willReturn('general');

        $message = $this->createMock(Message::class);
        $message->method('getChannel')->willReturn($channel);

        $this->messageRenderer
            ->expects($this->once())
            ->method('renderFeedItem')
            ->with($message, ['oob' => true, 'custom' => 'param'])
            ->willReturn('<div id="feed-item-1" hx-swap-oob="outerHTML">test</div>');

        $this->mercurePublisher
            ->expects($this->once())
            ->method('publishToChannel')
            ->with($channel, '<div id="feed-item-1" hx-swap-oob="outerHTML">test</div>', 'message_general');

        $this->broadcaster->broadcastMessageUpdate($message, ['custom' => 'param']);
    }

    #[Test]
    public function broadcastMessageUpdateDoesNothingWhenNoChannel(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getChannel')->willReturn(null);

        $this->messageRenderer->expects($this->never())->method('renderFeedItem');
        $this->mercurePublisher->expects($this->never())->method('publishToChannel');

        $this->broadcaster->broadcastMessageUpdate($message);
    }

    #[Test]
    public function broadcastMessageUpdateCatchesAndLogsException(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getSlug')->willReturn('general');

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(42);
        $message->method('getChannel')->willReturn($channel);

        $this->messageRenderer->method('renderFeedItem')->willThrowException(new \RuntimeException('Render failed'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Failed to broadcast Mercure update for message 42'));

        $this->broadcaster->broadcastMessageUpdate($message);
    }

    #[Test]
    public function broadcastMessageDeletionPublishesDeleteOob(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getSlug')->willReturn('general');
        $channel->method('isTodoList')->willReturn(false);

        $this->mercurePublisher
            ->expects($this->once())
            ->method('publishToChannel')
            ->with($channel, '<div id="feed-item-42" hx-swap-oob="delete"></div>', 'message_general');

        $this->broadcaster->broadcastMessageDeletion($channel, 42);
    }

    #[Test]
    public function broadcastMessageDeletionIncludesKanbanCardWhenTodoList(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getSlug')->willReturn('todo-channel');
        $channel->method('isTodoList')->willReturn(true);

        $this->mercurePublisher
            ->expects($this->once())
            ->method('publishToChannel')
            ->with(
                $channel,
                '<div id="pinned-banner-container" hx-swap-oob="true"></div><div id="feed-item-42" hx-swap-oob="delete"></div><div id="kanban-card-42" hx-swap-oob="delete"></div>',
                'message_todo-channel',
            );

        $this->broadcaster->broadcastMessageDeletion(
            $channel,
            42,
            '<div id="pinned-banner-container" hx-swap-oob="true"></div>',
        );
    }

    #[Test]
    public function broadcastPinRendersBannerAndMessages(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getSlug')->willReturn('general');

        $message = $this->createMock(Message::class);
        $prevMessage = $this->createMock(Message::class);

        $this->messageRenderer
            ->expects($this->exactly(2))
            ->method('renderFeedItem')
            ->willReturnMap([
                [$message, ['oob' => true], '<div id="feed-item-1">new pin</div>'],
                [$prevMessage, ['oob' => true], '<div id="feed-item-2">old pin</div>'],
            ]);

        $this->mercurePublisher
            ->expects($this->once())
            ->method('publishToChannel')
            ->with(
                $channel,
                '<div id="pinned-banner-container" hx-swap-oob="true"><span>Banner</span></div><div id="feed-item-1">new pin</div><div id="feed-item-2">old pin</div>',
                'message_general',
            );

        $this->broadcaster->broadcastPin($channel, $message, $prevMessage, '<span>Banner</span>');
    }

    #[Test]
    public function broadcastUnpinRendersEmptyBannerAndMessage(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getSlug')->willReturn('general');

        $message = $this->createMock(Message::class);

        $this->messageRenderer
            ->expects($this->once())
            ->method('renderFeedItem')
            ->with($message, ['oob' => true])
            ->willReturn('<div id="feed-item-1">unpinned msg</div>');

        $this->mercurePublisher
            ->expects($this->once())
            ->method('publishToChannel')
            ->with(
                $channel,
                '<div id="pinned-banner-container" hx-swap-oob="true"></div><div id="feed-item-1">unpinned msg</div>',
                'message_general',
            );

        $this->broadcaster->broadcastUnpin($channel, $message);
    }

    #[Test]
    public function broadcastRawPublishesDirectly(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getSlug')->willReturn('general');

        $this->mercurePublisher
            ->expects($this->once())
            ->method('publishToChannel')
            ->with($channel, '<div>raw</div>', 'message_general');

        $this->broadcaster->broadcastRaw($channel, '<div>raw</div>');
    }

    #[Test]
    public function publishCurrentModerationCountFetchesAndPublishes(): void
    {
        $this->messageRepository->expects($this->once())->method('countPendingModeration')->willReturn(7);

        $this->mercurePublisher->expects($this->once())->method('publishModerationCount')->with(7);

        $this->broadcaster->publishCurrentModerationCount();
    }
}
