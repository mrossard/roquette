<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Channel\ResolvedChannelContext;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;

final readonly class DashboardViewBuilder
{
    public function __construct(
        private SidebarDataProvider $sidebarDataProvider,
        private MessageFeedContextService $feedContextService,
        private TypingIndicatorService $typingIndicatorService,
        private MercurePublisher $mercurePublisher,
    ) {}

    /**
     * @param list<Message> $messages
     * @return array<string, mixed>
     */
    public function buildChannelViewContext(
        User $currentUser,
        ResolvedChannelContext $resolved,
        array $messages = [],
        ?int $firstUnreadMessageId = null,
    ): array {
        $sidebarData = $this->sidebarDataProvider->getSidebarData($currentUser);
        $notificationsEnabled = $resolved->resolveNotificationSetting($sidebarData['unreadCounts']);

        $typingUsers = $resolved->isMember
            ? $this->typingIndicatorService->getTypingUsers($resolved->channel, $currentUser)
            : [];

        $feedContext = $this->feedContextService->buildFeedContext($resolved->channel, $messages, $currentUser);

        return array_merge(
            [
                'activeChannel' => $resolved->channel,
                'messages' => $messages,
                'topic_url' => $this->mercurePublisher->getChannelTopic($resolved->channel),
                'firstUnreadMessageId' => $firstUnreadMessageId,
                'usersToInvite' => [],
                'isMember' => $resolved->isMember,
                'notificationsEnabled' => $notificationsEnabled,
                'typingUsers' => $typingUsers,
            ],
            $feedContext,
            $sidebarData,
        );
    }

    /**
     * @param array<int, mixed> $columns
     * @param array<int, Message> $untriagedMessages
     * @param array<int, User> $members
     * @return array<string, mixed>
     */
    public function buildKanbanViewContext(
        User $currentUser,
        Channel $channel,
        array $columns,
        array $untriagedMessages,
        array $members,
    ): array {
        $sidebarData = $this->sidebarDataProvider->getSidebarData($currentUser);
        $unreadCounts = $sidebarData['unreadCounts'];
        $activeRead = $unreadCounts[$channel->getId()] ?? null;
        $notificationsEnabled = $activeRead['notificationsEnabled'] ?? $channel->isDm();

        return array_merge([
            'activeChannel' => $channel,
            'messages' => [],
            'topic_url' => '',
            'firstUnreadMessageId' => null,
            'usersToInvite' => [],
            'isMember' => true,
            'notificationsEnabled' => $notificationsEnabled,
            'typingUsers' => [],
            'replyCounts' => [],
            'subchannelByParentMessageId' => [],
            'lastMessages' => [],
            'kanbanView' => true,
            'kanbanColumns' => $columns,
            'untriagedMessages' => $untriagedMessages,
            'kanbanMembers' => $members,
        ], $sidebarData);
    }
}
