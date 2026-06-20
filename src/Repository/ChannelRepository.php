<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Channel>
 */
class ChannelRepository extends ServiceEntityRepository
{
    private array $userGroupsCache = [];

    public function __construct(
        ManagerRegistry $registry,
        private readonly \App\Service\Group\GroupProviderInterface $groupProvider,
        private readonly UserGroupRepository $userGroupRepository,
    ) {
        parent::__construct($registry, Channel::class);
    }

    /**
     * @return string[]
     */
    private function getUserGroupIdentifiers(\App\Entity\User $user): array
    {
        $userId = $user->getId();
        if ($userId === null) {
            $providerGroups = $this->groupProvider->getGroupsForUser($user);
            $providerGroupIdentifiers = array_map(static fn($g) => $g->identifier, $providerGroups);

            $localGroups = $this->userGroupRepository->findGroupsForUser($user);
            $localGroupIdentifiers = array_map(static fn($g) => $g->getGroupIdentifier(), $localGroups);

            return array_unique(array_merge($providerGroupIdentifiers, $localGroupIdentifiers));
        }

        if (!isset($this->userGroupsCache[$userId])) {
            $providerGroups = $this->groupProvider->getGroupsForUser($user);
            $providerGroupIdentifiers = array_map(static fn($g) => $g->identifier, $providerGroups);

            $localGroups = $this->userGroupRepository->findGroupsForUser($user);
            $localGroupIdentifiers = array_map(static fn($g) => $g->getGroupIdentifier(), $localGroups);

            $this->userGroupsCache[$userId] = array_unique(array_merge($providerGroupIdentifiers, $localGroupIdentifiers));
        }

        return $this->userGroupsCache[$userId];
    }

    public function findAllForUser(\App\Entity\User $user): array
    {
        $groupIdentifiers = $this->getUserGroupIdentifiers($user);

        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.members', 'm')
            ->leftJoin('c.userGroup', 'ug')
            ->addSelect('ug');

        if (!empty($groupIdentifiers)) {
            $qb->leftJoin('c.groupSubscriptions', 'gs')
                ->where('m.id = :userId OR gs.groupIdentifier IN (:groupIdentifiers)')
                ->setParameter('groupIdentifiers', $groupIdentifiers);
        } else {
            $qb->where('m.id = :userId');
        }

        $joinedChannels = $qb
            ->setParameter('userId', $user->getId())
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        // Apply custom channel ordering if it exists
        $order = $user->getChannelOrder();
        if ($order !== null && $order !== []) {
            $positionMap = array_flip($order);
            usort($joinedChannels, static function (Channel $a, Channel $b) use ($positionMap) {
                $posA = $positionMap[$a->getId()] ?? null;
                $posB = $positionMap[$b->getId()] ?? null;

                if ($posA !== null && $posB !== null) {
                    return $posA <=> $posB;
                }
                if ($posA !== null) {
                    return -1;
                }
                if ($posB !== null) {
                    return 1;
                }

                $cmp = $a->getCreatedAt() <=> $b->getCreatedAt();
                return $cmp !== 0 ? $cmp : $a->getId() <=> $b->getId();
            });
        }

        return $joinedChannels;
    }

    /** @return Channel[] */
    public function findAllPublic(): array
    {
        return $this->findBy(['isPrivate' => false, 'parentMessage' => null], ['name' => 'ASC'], 100);
    }

    public function findDmBetween(\App\Entity\User $user1, \App\Entity\User $user2): ?Channel
    {
        $qb = $this
            ->createQueryBuilder('c')
            ->join('c.members', 'm1')
            ->join('c.members', 'm2')
            ->where('c.isDm = true')
            ->andWhere('m1.id = :u1')
            ->andWhere('m2.id = :u2')
            ->setParameter('u1', $user1->getId())
            ->setParameter('u2', $user2->getId());

        // If DM with self
        if ($user1->getId() === $user2->getId()) {
            // We need to make sure there is only 1 member, or handle differently.
            // But typical systems only have the 1 user.
            // Let's filter by the number of members being 1 if needed,
            // but the join on m1 and m2 with same ID works as long as the user is a member.
        }

        $results = $qb->getQuery()->getResult();
        foreach ($results as $channel) {
            // Double check that members count is correct (2 for normal DMs, 1 for self-DMs)
            $memberCount = $channel->getMembers()->count();
            if ($user1->getId() === $user2->getId()) {
                if ($memberCount === 1) {
                    return $channel;
                }
            } else {
                if ($memberCount === 2) {
                    return $channel;
                }
            }
        }

        return null;
    }

    public function searchByName(string $query, \App\Entity\User $user): array
    {
        $groupIdentifiers = $this->getUserGroupIdentifiers($user);

        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.members', 'm')
            ->where('c.isDm = false')
            ->andWhere('c.parentMessage IS NULL')
            ->andWhere('LOWER(c.name) LIKE :query OR LOWER(c.description) LIKE :query');

        if (!empty($groupIdentifiers)) {
            $qb->leftJoin('c.groupSubscriptions', 'gs')
                ->andWhere('c.isPrivate = false OR m.id = :userId OR gs.groupIdentifier IN (:groupIdentifiers)')
                ->setParameter('groupIdentifiers', $groupIdentifiers);
        } else {
            $qb->andWhere('c.isPrivate = false OR m.id = :userId');
        }

        return $qb
            ->setParameter('query', '%' . strtolower($query) . '%')
            ->setParameter('userId', $user->getId())
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();
    }

    public function canUserAccess(Channel $channel, User $user): bool
    {
        if (!$channel->isPrivate()) {
            return true;
        }

        // Direct member
        if ($channel->getMembers()->contains($user)) {
            return true;
        }

        // Creator is always allowed
        if ($channel->getCreator() && $channel->getCreator()->getId() === $user->getId()) {
            return true;
        }

        // Check group subscriptions
        $subscriptions = $channel->getGroupSubscriptions();
        if ($subscriptions->isEmpty()) {
            return false;
        }

        $userGroupIdentifiers = $this->getUserGroupIdentifiers($user);

        foreach ($subscriptions as $subscription) {
            if (in_array($subscription->getGroupIdentifier(), $userGroupIdentifiers, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return Channel[] */
    public function findSubChannelsForUser(User $user): array
    {
        return $this
            ->createQueryBuilder('c')
            ->join('c.members', 'm')
            ->where('m.id = :userId')
            ->andWhere('c.parentMessage IS NOT NULL')
            ->orderBy('c.createdAt', 'ASC')
            ->setParameter('userId', $user->getId())
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns sub-channels of a given parent channel that the user is a member of.
     *
     * @return Channel[]
     */
    public function findSubChannelsOf(Channel $parent, User $user): array
    {
        return $this
            ->createQueryBuilder('c')
            ->join('c.members', 'm')
            ->where('m.id = :userId')
            ->andWhere('c.parentMessage = :parent')
            ->orderBy('c.createdAt', 'ASC')
            ->setParameter('userId', $user->getId())
            ->setParameter('parent', $parent)
            ->getQuery()
            ->getResult();
    }

    /**
     * Load all subchannels of a given channel in one query via JOIN.
     * Returns an array indexed by parent message id.
     *
     * @return array<int, Channel>
     */
    public function findSubchannelsByChannel(Channel $channel): array
    {
        $result = $this->createQueryBuilder('c')
            ->join('c.parentMessage', 'pm')
            ->where('pm.channel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($result as $sub) {
            $indexed[$sub->getParentMessage()->getId()] = $sub;
        }

        return $indexed;
    }

    public function hasUserParticipated(Channel $channel, User $user): bool
    {
        $count = $this
            ->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(\App\Entity\Message::class, 'm')
            ->where('m.channel = :channel')
            ->andWhere('m.author = :user')
            ->setParameter('channel', $channel)
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
