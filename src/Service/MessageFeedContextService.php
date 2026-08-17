<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;

class MessageFeedContextService
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly ChannelRepository $channelRepository,
    ) {}

    /**
     * Builds feed context metadata for a list of messages in a given channel.
     *
     * @param list<Message> $messages
     * @return array{
     *     replyCounts: array<int, int>,
     *     subchannelByParentMessageId: array<int, Channel>,
     *     savedMessageIds?: array<int, int>
     * }
     */
    public function buildFeedContext(Channel $channel, array $messages, ?User $currentUser = null): array
    {
        $messageIds = array_values(array_filter(
            array_map(static fn(Message $m) => $m->getId(), $messages),
            static fn(?int $id) => $id !== null,
        ));

        $context = [
            'replyCounts' => $this->messageRepository->findReplyCounts($messageIds),
            'subchannelByParentMessageId' => $this->channelRepository->findSubchannelsByChannel($channel),
        ];

        if ($currentUser !== null) {
            $context['savedMessageIds'] = $this->messageRepository->findSavedMessageIdsForUser($currentUser);
        }

        return $context;
    }
}
