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
    ) {}

    public function canUserAccess(Channel $channel, User $user): bool
    {
        if (!$channel->isPrivate()) {
            return true;
        }

        if ($channel->getMembers()->contains($user)) {
            return true;
        }

        if ($channel->getCreator() !== null && $channel->getCreator()->getId() === $user->getId()) {
            return true;
        }

        $subscriptions = $channel->getGroupSubscriptions();
        if ($subscriptions->isEmpty()) {
            return false;
        }

        // Check local groups via a single query
        $localGroupMatch = $this->entityManager->createQueryBuilder()
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
