<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Message\PushNotificationMessage;
use App\Repository\UserChannelReadRepository;
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
        private UserChannelReadRepository $ucrRepo,
        private TranslatorInterface $translator,
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

    // -------------------------------------------------------------------------
    // Generic publish helpers
    // -------------------------------------------------------------------------

    public function publishToChannel(Channel $channel, array|string $payload, ?string $type = null): void
    {
        $data = is_array($payload) ? json_encode($payload) : $payload;
        $this->bus->dispatch(
            new Update($this->getChannelTopic($channel), $data, $channel->isPrivate() || $channel->isDm(), null, $type),
        );
    }

    public function publishToUser(User $user, array|string $payload, ?string $type = null): void
    {
        $data = is_array($payload) ? json_encode($payload) : $payload;
        $this->bus->dispatch(new Update($this->getUserTopic($user), $data, true, null, $type));
    }

    public function publishToTopic(
        string $topicUrl,
        array|string $payload,
        bool $private = false,
        ?string $type = null,
    ): void {
        $data = is_array($payload) ? json_encode($payload) : $payload;
        $this->bus->dispatch(new Update($topicUrl, $data, $private, null, $type));
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
        $this->publishToChannel($channel, $renderedHtml, 'message_' . $channel->getSlug());

        $channelName = $channel->isDm() ? 'Message direct' : '#' . $channel->getName();
        if ($channel->isSubChannel() && $channel->getParentMessage() !== null) {
            $parentChannelName = '#' . $channel->getParentMessage()->getChannel()->getName();
            $channelName .= ' (discussion de ' . $parentChannelName . ')';
        }

        $content = $this->buildContentSummary($message);

        $this->publishToChannel(
            $channel,
            [
                'channelSlug' => $channel->getSlug(),
                'channelId' => $channel->getId(),
                'messageId' => $message->getId(),
                'author' => $author->getUsername(),
                'authorDisplayName' => $author->getDisplayName() ?: $author->getUsername(),
                'channelName' => $channelName,
                'content' => $content,
                'isDm' => $channel->isDm(),
                'isSubChannel' => $channel->isSubChannel(),
                'parentChannelId' => $channel->getParentMessage()?->getChannel()->getId(),
                'parentChannelSlug' => $channel->getParentMessage()?->getChannel()->getSlug(),
            ],
            'channel_notification',
        );

        $title = $channelName;
        $body = ($author->getDisplayName() ?: $author->getUsername()) . ': ' . $content;
        $url = '/channels/' . $channel->getSlug();

        foreach ($channel->getMembers() as $member) {
            if ($member === $author) {
                continue;
            }

            $this->bus->dispatch(new PushNotificationMessage(
                userId: (int) $member->getId(),
                title: $title,
                body: $body,
                url: $url,
            ));
        }
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
