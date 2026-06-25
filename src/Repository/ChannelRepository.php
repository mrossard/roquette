<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\GroupSubscription;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Service\Group\GroupProviderInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Channel>
 */
class ChannelRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly GroupProviderInterface $groupProvider,
    ) {
        parent::__construct($registry, Channel::class);
    }

    public function findAllForUser(User $user): array
    {
        $providerGroups = $this->groupProvider->getGroupsForUser($user);
        $providerGroupIdentifiers = array_map(static fn($g) => $g->identifier, $providerGroups);

        $qb = $this
            ->createQueryBuilder('c')
            ->leftJoin('c.userGroup', 'ug')
            ->addSelect('ug');

        $conditions = $qb->expr()->orX(
            $qb->expr()->isMemberOf(':userId', 'c.members'),
        );

        $localGroupDql = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(gs_local.channel)')
            ->from(GroupSubscription::class, 'gs_local')
            ->join(UserGroup::class, 'ug_local', 'WITH', 'ug_local.groupIdentifier = gs_local.groupIdentifier')
            ->join('ug_local.members', 'ugm_local')
            ->where('ugm_local.id = :userId')
            ->getDQL();

        $conditions->add($qb->expr()->in('c.id', $localGroupDql));

        if (!empty($providerGroupIdentifiers)) {
            $externalGroupDql = $this->getEntityManager()->createQueryBuilder()
                ->select('IDENTITY(gs_ext.channel)')
                ->from(GroupSubscription::class, 'gs_ext')
                ->where('gs_ext.groupIdentifier IN (:providerGroupIdentifiers)')
                ->getDQL();

            $conditions->add($qb->expr()->in('c.id', $externalGroupDql));
            $qb->setParameter('providerGroupIdentifiers', $providerGroupIdentifiers);
        }

        $joinedChannels = $qb
            ->where($conditions)
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

    public function findDmBetween(User $user1, User $user2): ?Channel
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

    public function searchByName(string $query, User $user): array
    {
        $providerGroups = $this->groupProvider->getGroupsForUser($user);
        $providerGroupIdentifiers = array_map(static fn($g) => $g->identifier, $providerGroups);

        $qb = $this
            ->createQueryBuilder('c')
            ->leftJoin('c.members', 'm')
            ->where('c.isDm = false')
            ->andWhere('c.parentMessage IS NULL')
            ->andWhere('LOWER(c.name) LIKE :query OR LOWER(c.description) LIKE :query');

        $accessConditions = $qb->expr()->orX(
            'c.isPrivate = false',
            'm.id = :userId',
        );

        $localGroupDql = $this->_em->createQueryBuilder()
            ->select('IDENTITY(gs_local.channel)')
            ->from(GroupSubscription::class, 'gs_local')
            ->join(UserGroup::class, 'ug_local', 'WITH', 'ug_local.groupIdentifier = gs_local.groupIdentifier')
            ->join('ug_local.members', 'ugm_local')
            ->where('ugm_local.id = :userId')
            ->getDQL();

        $accessConditions->add($qb->expr()->in('c.id', $localGroupDql));

        if (!empty($providerGroupIdentifiers)) {
            $externalGroupDql = $this->_em->createQueryBuilder()
                ->select('IDENTITY(gs_ext.channel)')
                ->from(GroupSubscription::class, 'gs_ext')
                ->where('gs_ext.groupIdentifier IN (:providerGroupIdentifiers)')
                ->getDQL();

            $accessConditions->add($qb->expr()->in('c.id', $externalGroupDql));
            $qb->setParameter('providerGroupIdentifiers', $providerGroupIdentifiers);
        }

        return $qb
            ->setParameter('query', '%' . strtolower($query) . '%')
            ->setParameter('userId', $user->getId())
            ->andWhere($accessConditions)
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();
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
        $result = $this
            ->createQueryBuilder('c')
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
