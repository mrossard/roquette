<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Repository\MessageRepository;
use Psr\Log\LoggerInterface;

class MessageBroadcaster
{
    public function __construct(
        private readonly MessageRenderer $messageRenderer,
        private readonly MercurePublisher $mercurePublisher,
        private readonly MessageRepository $messageRepository,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Broadcasts an updated message HTML (with OOB swap) to the message's channel.
     *
     * @param array<string, mixed> $extraParams
     */
    public function broadcastMessageUpdate(Message $message, array $extraParams = []): void
    {
        $channel = $message->getChannel();
        if ($channel === null) {
            return;
        }

        try {
            $html = $this->messageRenderer->renderFeedItem($message, array_merge(['oob' => true], $extraParams));
            $this->mercurePublisher->publishToChannel($channel, $html, 'message_' . $channel->getSlug());
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf(
                'Failed to broadcast Mercure update for message %d: %s',
                $message->getId() ?? 0,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Broadcasts a message deletion (with OOB delete swap) to the channel.
     */
    public function broadcastMessageDeletion(Channel $channel, int $messageId, ?string $extraOobHtml = null): void
    {
        try {
            $oobHtml = sprintf('<div id="feed-item-%d" hx-swap-oob="delete"></div>', $messageId);
            if ($channel->isTodoList()) {
                $oobHtml .= sprintf('<div id="kanban-card-%d" hx-swap-oob="delete"></div>', $messageId);
            }
            if ($extraOobHtml !== null) {
                $oobHtml = $extraOobHtml . $oobHtml;
            }
            $this->mercurePublisher->publishToChannel($channel, $oobHtml, 'message_' . $channel->getSlug());
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf(
                'Failed to broadcast Mercure deletion for message %d: %s',
                $messageId,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Broadcasts a pinned message banner update and message feed item updates.
     */
    public function broadcastPin(
        Channel $channel,
        Message $message,
        ?Message $previousPinnedMessage = null,
        ?string $bannerHtml = null,
    ): void {
        try {
            $bannerOob = sprintf('<div id="pinned-banner-container" hx-swap-oob="true">%s</div>', $bannerHtml ?? '');
            $messageHtml = $this->messageRenderer->renderFeedItem($message, ['oob' => true]);
            $previousHtml = $previousPinnedMessage !== null
                ? $this->messageRenderer->renderFeedItem($previousPinnedMessage, ['oob' => true])
                : '';

            $this->mercurePublisher->publishToChannel(
                $channel,
                $bannerOob . $messageHtml . $previousHtml,
                'message_' . $channel->getSlug(),
            );
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf(
                'Failed to broadcast Mercure pin for message %d: %s',
                $message->getId() ?? 0,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Broadcasts an unpinned message banner reset and message feed item update.
     */
    public function broadcastUnpin(Channel $channel, Message $message): void
    {
        try {
            $bannerOob = '<div id="pinned-banner-container" hx-swap-oob="true"></div>';
            $messageHtml = $this->messageRenderer->renderFeedItem($message, ['oob' => true]);

            $this->mercurePublisher->publishToChannel(
                $channel,
                $bannerOob . $messageHtml,
                'message_' . $channel->getSlug(),
            );
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf(
                'Failed to broadcast Mercure unpin for message %d: %s',
                $message->getId() ?? 0,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Broadcasts arbitrary raw OOB HTML to the channel message stream.
     */
    public function broadcastRaw(Channel $channel, string $oobHtml): void
    {
        try {
            $this->mercurePublisher->publishToChannel($channel, $oobHtml, 'message_' . $channel->getSlug());
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf(
                'Failed to broadcast Mercure raw update to channel %s: %s',
                $channel->getSlug(),
                $e->getMessage(),
            ));
        }
    }

    /**
     * Broadcasts the current pending moderation count to the admin moderation Mercure topic.
     */
    public function publishCurrentModerationCount(): void
    {
        try {
            $pendingCount = $this->messageRepository->countPendingModeration();
            $this->mercurePublisher->publishModerationCount($pendingCount);
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf('Failed to publish moderation count: %s', $e->getMessage()));
        }
    }
}
