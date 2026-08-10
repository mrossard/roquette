<?php

declare(strict_types=1);

namespace App\Service;

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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSidebarData(User $user): array
    {
        $channels = $this->channelRepository->findAllForUser($user);
        $workspaces = $this->workspaceRepository->findAllForUser($user);
        $pendingInvitations = $this->invitationRepository->findPendingForUser($user);

        $ucrRepo = $this->entityManager->getRepository(UserChannelRead::class);
        $unreadCounts = $ucrRepo->getUnreadCounts($user);

        $workspaceUnreadCounts = [];
        foreach ($channels as $ch) {
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
}
