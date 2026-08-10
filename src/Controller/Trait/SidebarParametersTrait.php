<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\InvitationRepository;
use App\Repository\MessageRepository;
use App\Repository\WorkspaceRepository;
use App\Service\ChannelManager;
use App\Service\SidebarDataProvider;
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
        ?SidebarDataProvider $sidebarDataProvider = null,
    ): array {
        if ($sidebarDataProvider === null) {
            $sidebarDataProvider = new SidebarDataProvider(
                $channelRepository,
                $workspaceRepository,
                $invitationRepository,
                $messageRepository,
                $channelManager,
                $entityManager,
            );
        }

        return $sidebarDataProvider->getSidebarData($user);
    }
}
