<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Centralises all Mercure SSE publish operations.
 *
 * Extracted from DashboardController to eliminate duplicated notification loops.
 */
class MercurePublisher
{
    public function __construct(
        private MessageBusInterface $bus,
        private string $mercureTopicPrefix,
        private TranslatorInterface $translator,
        private ?HubInterface $hub = null,
    ) {}

    // -------------------------------------------------------------------------
    // Topic helpers
    // -------------------------------------------------------------------------

    public function getChannelTopic(Channel $channel): string
    {
        return $this->mercureTopicPrefix . '/channels/' . $channel->getSlug();
    }

    public function getUserTopic(User $user): string
    {
        return $this->mercureTopicPrefix . '/users/' . $user->getUsername();
    }

    public function getStatusTopic(): string
    {
        return $this->mercureTopicPrefix . '/users/status';
    }

    public function getAdminModerationTopic(): string
    {
        return $this->mercureTopicPrefix . '/admin/moderation';
    }

    public function publishModerationCount(int $pendingCount): void
    {
        $this->publishToTopic(
            $this->getAdminModerationTopic(),
            [
                'type' => 'moderation_count_changed',
                'count' => $pendingCount,
            ],
            false,
            'moderation_count_changed',
        );
    }

    // -------------------------------------------------------------------------
    // Generic publish helpers
    // -------------------------------------------------------------------------

    public function publishToChannel(Channel $channel, array|string $payload, ?string $type = null): void
    {
        $isPrivate = $channel->isDm() || $channel->isPrivate();
        if (!$isPrivate && $channel->isWorkspaceChannel() && !$channel->getWorkspace()->isPublic()) {
            $isPrivate = true;
        }

        $this->publishToTopic($this->getChannelTopic($channel), $payload, $isPrivate, $type);
    }

    public function publishToUser(User $user, array|string $payload, ?string $type = null): void
    {
        $this->publishToTopic($this->getUserTopic($user), $payload, true, $type);
    }

    public function publishToTopic(
        string $topicUrl,
        array|string $payload,
        bool $private = false,
        ?string $type = null,
    ): void {
        $data = $this->encodePayload($payload);
        $this->sendUpdate(new Update($topicUrl, $data, $private, null, $type));
    }

    private function encodePayload(array|string $payload): string
    {
        return is_array($payload) ? json_encode($payload, JSON_THROW_ON_ERROR) : $payload;
    }

    private function sendUpdate(Update $update): void
    {
        if ($this->hub !== null) {
            $this->hub->publish($update);

            return;
        }

        $this->bus->dispatch($update);
    }

    public function publishUserStatus(User $user): void
    {
        $this->publishToTopic(
            $this->getStatusTopic(),
            [
                'type' => 'user_status_changed',
                'username' => $user->getUsername(),
                'status' => $user->getStatus(),
                'statusLabel' => $user->getStatusLabel(),
                'statusOverride' => $user->getStatusOverride() ?? 'auto',
                'lastActive' => $user->getLastActiveAt()?->getTimestamp(),
            ],
            true,
            'user_status_changed',
        );
    }

    // -------------------------------------------------------------------------
    // High-level operations
    // -------------------------------------------------------------------------

    /**
     * Publishes a new message HTML to the channel topic and sends personal
     * unread notifications to each member (excluding the author).
     *
     * @param string $messageText  Raw message text (used for mention detection)
     * @param string $renderedHtml Pre-rendered feed item HTML
     */
    public function publishNewMessage(
        Channel $channel,
        Message $message,
        User $author,
        string $messageText,
        string $renderedHtml,
    ): void {
        $channelName = $channel->isDm() ? 'Message direct' : '#' . $channel->getName();
        if ($channel->isSubChannel() && $channel->getParentMessage() !== null) {
            $parentChannelName = '#' . $channel->getParentMessage()->getChannel()->getName();
            $channelName .= ' (discussion de ' . $parentChannelName . ')';
        }

        $content = $this->buildContentSummary($message);

        $authorDisplayName = $author->getDisplayName();
        $displayName = ($authorDisplayName !== null && $authorDisplayName !== '') ? $authorDisplayName : $author->getUsername();

        $notificationData = [
            'channelSlug' => $channel->getSlug(),
            'channelId' => $channel->getId(),
            'messageId' => $message->getId(),
            'author' => $author->getUsername(),
            'authorDisplayName' => $displayName,
            'channelName' => $channelName,
            'content' => $content,
            'isDm' => $channel->isDm(),
            'isSubChannel' => $channel->isSubChannel(),
            'parentChannelId' => $channel->getParentMessage()?->getChannel()->getId(),
            'parentChannelSlug' => $channel->getParentMessage()?->getChannel()->getSlug(),
            'workspaceId' => $channel->getWorkspace()?->getId(),
            'workspaceSlug' => $channel->getWorkspace()?->getSlug(),
        ];

        $this->publishToChannel($channel, $renderedHtml, 'message_' . $channel->getSlug());
        $this->publishToChannel($channel, $notificationData, 'channel_notification');

        $title = $channelName;
        $body = $displayName . ': ' . $content;
        $url = '/channels/' . $channel->getSlug();

        $this->bus->dispatch(new \App\Message\ChannelNotificationMessage(
            channelId: (int) $channel->getId(),
            messageId: (int) $message->getId(),
            authorId: (int) $author->getId(),
            title: $title,
            body: $body,
            url: $url,
        ));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildContentSummary(Message $message): string
    {
        if ($message->getPoll()) {
            return $this->translator->trans('a créé un sondage : %question%', [
                '%question%' => $message->getPoll()->getQuestion(),
            ]);
        }

        if ($message->getContent()) {
            return $message->getContent();
        }

        if ($message->getFileName()) {
            return $this->translator->trans('a envoyé un fichier : %filename%', [
                '%filename%' => $message->getFileName(),
            ]);
        }

        return $this->translator->trans('Nouveau message');
    }
}
