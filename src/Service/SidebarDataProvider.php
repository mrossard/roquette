<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\User;
use App\Entity\UserChannelRead;
use App\Repository\ChannelRepository;
use App\Repository\InvitationRepository;
use App\Repository\MessageRepository;
use App\Repository\WorkspaceRepository;
use Doctrine\ORM\EntityManagerInterface;

class SidebarDataProvider
{
    public function __construct(
        private readonly ChannelRepository $channelRepository,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly InvitationRepository $invitationRepository,
        private readonly MessageRepository $messageRepository,
        private readonly ChannelManager $channelManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly ReadTrackingService $readTrackingService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSidebarData(User $user): array
    {
        $channels = $this->channelRepository->findAllForUser($user);
        $this->readTrackingService->ensureUserChannelReads($user, $channels);

        $workspaces = $this->workspaceRepository->findAllForUser($user);
        $pendingInvitations = $this->invitationRepository->findPendingForUser($user);

        $ucrRepo = $this->entityManager->getRepository(UserChannelRead::class);
        $unreadCounts = $ucrRepo->getUnreadCounts($user);

        $workspaceUnreadCounts = $this->computeWorkspaceUnreadCounts($channels, $unreadCounts);
        $subChannelsByParent = $this->channelManager->buildSubChannelsByParent($channels);

        $channelIds = array_map(static fn($c) => $c->getId(), $channels);
        $lastMessages = $this->messageRepository->findLastMessagesForChannels($channelIds);

        return [
            'channels' => $channels,
            'workspaces' => $workspaces,
            'pendingInvitations' => $pendingInvitations,
            'unreadCounts' => $unreadCounts,
            'workspaceUnreadCounts' => $workspaceUnreadCounts,
            'subChannelsByParent' => $subChannelsByParent,
            'lastMessages' => $lastMessages,
        ];
    }

    /**
     * @param Channel[] $channels
     * @param array<int, array{count: int, hasMention: bool, notificationsEnabled?: bool}> $unreadCounts
     * @return array<int, int>
     */
    public function computeWorkspaceUnreadCounts(array $channels, array $unreadCounts): array
    {
        $workspaceUnreadCounts = [];
        $processedChannelIds = [];
        foreach ($channels as $ch) {
            $chId = $ch->getId();
            if ($chId !== null && array_key_exists($chId, $processedChannelIds)) {
                continue;
            }
            if ($chId !== null) {
                $processedChannelIds[$chId] = true;
            }

            $ws = $ch->getWorkspace();
            if (!$ws) {
                continue;
            }
            $wsId = $ws->getId();
            if (!array_key_exists($wsId, $workspaceUnreadCounts)) {
                $workspaceUnreadCounts[$wsId] = 0;
            }
            $workspaceUnreadCounts[$wsId] += $unreadCounts[$ch->getId()]['count'] ?? 0;
        }

        return $workspaceUnreadCounts;
    }
}
