<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use App\Entity\User;
use App\Entity\UserChannelRead;
use App\Repository\ChannelRepository;
use App\Repository\InvitationRepository;
use App\Repository\MessageRepository;
use App\Repository\WorkspaceRepository;
use App\Service\ChannelManager;
use Doctrine\ORM\EntityManagerInterface;

trait SidebarParametersTrait
{
    /**
     * @return array<string, mixed>
     */
    private function getSidebarParameters(
        User $user,
        ChannelRepository $channelRepository,
        WorkspaceRepository $workspaceRepository,
        InvitationRepository $invitationRepository,
        MessageRepository $messageRepository,
        ChannelManager $channelManager,
        EntityManagerInterface $entityManager,
    ): array {
        $channels = $channelRepository->findAllForUser($user);
        $workspaces = $workspaceRepository->findAllForUser($user);
        $pendingInvitations = $invitationRepository->findPendingForUser($user);

        $ucrRepo = $entityManager->getRepository(UserChannelRead::class);
        $unreadCounts = $ucrRepo->getUnreadCounts($user);

        // Aggregate unread counts per workspace
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

        $subChannelsByParent = $channelManager->buildSubChannelsByParent($channels);

        $channelIds = array_map(static fn($c) => $c->getId(), $channels);
        $lastMessages = $messageRepository->findLastMessagesForChannels($channelIds);

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
