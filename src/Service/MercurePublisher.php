<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\UserChannelRead;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Centralises all Mercure SSE publish operations.
 *
 * Extracted from DashboardController to eliminate duplicated notification loops
 * that appeared identically in publish() and postReply().
 */
class MercurePublisher
{
    public function __construct(
        private MessageBusInterface $bus,
        private string $mercureTopicPrefix,
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

    public function publishToChannel(Channel $channel, array $payload): void
    {
        $this->bus->dispatch(
            new Update($this->getChannelTopic($channel), json_encode($payload), $channel->isPrivate()),
        );
    }

    public function publishToUser(User $user, array $payload): void
    {
        $this->bus->dispatch(new Update($this->getUserTopic($user), json_encode($payload), true)); // user topics are always private
    }

    public function publishToTopic(string $topicUrl, array $payload, bool $private = false): void
    {
        $this->bus->dispatch(new Update($topicUrl, json_encode($payload), $private));
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
        EntityManagerInterface $em,
        ?int $parentId = null,
        ?int $replyCount = null,
        string $memberNotificationPrefix = '',
    ): void {
        $payload = [
            'html' => $renderedHtml,
            'user' => $author->getUsername(),
            'author' => $author->getUsername(),
            'authorDisplayName' => $author->getDisplayName() ?: $author->getUsername(),
            'channelSlug' => $channel->getSlug(),
            'channelName' => $channel->isDm() ? 'Message direct' : '#' . $channel->getName(),
            'content' => $this->buildContentSummary($message),
        ];

        if ($parentId !== null) {
            $payload['parentId'] = $parentId;
            $payload['replyCount'] = $replyCount ?? 0;
        }

        $this->publishToChannel($channel, $payload);
        $this->publishMemberNotifications($channel, $message, $author, $messageText, $em, $memberNotificationPrefix);
    }

    /**
     * Sends per-member unread / mention notifications for a message.
     * Skips the author. Respects notification preferences and @mentions.
     */
    public function publishMemberNotifications(
        Channel $channel,
        Message $message,
        User $author,
        string $messageText,
        EntityManagerInterface $em,
        string $contentPrefix = '',
    ): void {
        $ucrRepo = $em->getRepository(UserChannelRead::class);

        foreach ($channel->getMembers() as $member) {
            if ($member->getId() === $author->getId()) {
                continue;
            }

            $ucr = $ucrRepo->findOneBy(['user' => $member, 'channel' => $channel]);
            $notificationsEnabled = $ucr ? $ucr->isNotificationsEnabled() : null;
            if ($notificationsEnabled === null) {
                $notificationsEnabled = $channel->isDm();
            }

            $isMentioned = false;
            if ($messageText !== '') {
                $pattern = '/@' . preg_quote($member->getUsername(), '/') . '\b/i';
                $isMentioned = (bool) preg_match($pattern, $messageText);
            }

            $content = $contentPrefix . $this->buildContentSummary($message);

            $this->publishToUser($member, [
                'channelSlug' => $channel->getSlug(),
                'channelId' => $channel->getId(),
                'messageId' => $message->getId(),
                'author' => $author->getUsername(),
                'authorDisplayName' => $author->getDisplayName() ?: $author->getUsername(),
                'channelName' => $channel->isDm() ? 'Message direct' : '#' . $channel->getName(),
                'content' => $content,
                'notificationsEnabled' => $isMentioned
                    ? $member->isMentionNotificationsEnabled()
                    : $notificationsEnabled,
                'isMention' => $isMentioned,
                'isMentionNotificationAllowed' => $member->isMentionNotificationsEnabled(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildContentSummary(Message $message): string
    {
        if ($message->getContent()) {
            return $message->getContent();
        }

        if ($message->getFileName()) {
            return 'a envoyé un fichier : ' . $message->getFileName();
        }

        return 'Nouveau message';
    }
}
