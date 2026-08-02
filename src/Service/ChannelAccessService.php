<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\GroupSubscription;
use App\Entity\User;
use App\Entity\UserGroup;

use App\Service\Group\GroupProviderInterface;
use Doctrine\ORM\EntityManagerInterface;

class ChannelAccessService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GroupProviderInterface $groupProvider,
        private readonly \App\Service\WorkspaceManager $workspaceManager,
    ) {}

    public function canUserAccess(Channel $channel, User $user): bool
    {
        // Workspace channels: access is granted if user is a workspace member
        $workspace = $channel->getWorkspace();
        if ($workspace !== null) {
            return $this->workspaceManager->isUserMember($workspace, $user);
        }

        // Legacy non-private channels (no workspace) — public access
        if (!$channel->isPrivate()) {
            return true;
        }

        // Legacy private channels: direct member only.
        // The creator is always added as a member at channel creation time,
        // so a removed creator must not regain access.
        if ($channel->getMembers()->contains($user)) {
            return true;
        }

        // Legacy group subscriptions
        $subscriptions = $channel->getGroupSubscriptions();
        if ($subscriptions->isEmpty()) {
            return false;
        }

        // Check local groups via a single query
        $localGroupMatch = $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(gs.id)')
            ->from(GroupSubscription::class, 'gs')
            ->join(UserGroup::class, 'ug', 'WITH', 'ug.groupIdentifier = gs.groupIdentifier')
            ->join('ug.members', 'm')
            ->where('gs.channel = :channel')
            ->andWhere('m.id = :userId')
            ->setParameter('channel', $channel)
            ->setParameter('userId', $user->getId())
            ->getQuery()
            ->getSingleScalarResult();

        if ((int) $localGroupMatch > 0) {
            return true;
        }

        // Check external provider groups
        $providerGroups = $this->groupProvider->getGroupsForUser($user);
        $providerIdentifiers = array_map(static fn($g) => (string) $g->identifier, $providerGroups);

        foreach ($subscriptions as $subscription) {
            if (in_array($subscription->getGroupIdentifier(), $providerIdentifiers, true)) {
                return true;
            }
        }

        return false;
    }
}
