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
            ->leftJoin('c.workspace', 'w')
            ->addSelect('w')
            ->leftJoin('w.userGroup', 'ug')
            ->addSelect('ug')
            ->leftJoin('c.members', 'm')
            ->addSelect('m')
            ->leftJoin('c.parentMessage', 'pm')
            ->addSelect('pm')
            ->leftJoin('pm.channel', 'pmc')
            ->addSelect('pmc');

        $workspaceConditions = $this->buildWorkspaceAccessConditions($qb, $providerGroupIdentifiers);
        $groupConditions = $this->buildGroupAccessConditions($qb, $providerGroupIdentifiers, 'c');

        $conditions = $qb->expr()->orX(
            // Direct channel membership (private channels, DMs)
            $qb->expr()->isMemberOf(':userId', 'c.members'),
            // Channels in workspaces the user belongs to
            $workspaceConditions,
            // Group-subscribed channels
            $groupConditions,
        );

        $joinedChannels = $qb
            ->where($conditions)
            ->setParameter('userId', $user->getId())
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        // Deduplicate channels (since joining c.members produces multiple rows per channel)
        $uniqueChannels = [];
        foreach ($joinedChannels as $channel) {
            $uniqueChannels[$channel->getId()] = $channel;
        }
        $joinedChannels = array_values($uniqueChannels);

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
        $publicWorkspace = $this
            ->getEntityManager()
            ->getRepository(\App\Entity\Workspace::class)
            ->findOneBy(['isPublic' => true]);

        if (!$publicWorkspace) {
            return [];
        }

        return $this->findBy(['workspace' => $publicWorkspace, 'parentMessage' => null], ['name' => 'ASC'], 100);
    }

    /** @return Channel[] */
    public function findPublicForWorkspace(\App\Entity\Workspace $workspace): array
    {
        return $this->findBy([
            'workspace' => $workspace,
            'isPrivate' => false,
            'isDm' => false,
            'parentMessage' => null,
        ], ['name' => 'ASC']);
    }

    /** @return Channel[] */
    public function findForWorkspace(\App\Entity\Workspace $workspace, User $user): array
    {
        return $this
            ->createQueryBuilder('c')
            ->where('c.workspace = :workspace')
            ->andWhere('c.parentMessage IS NULL')
            ->andWhere('c.isDm = false')
            ->orderBy('c.createdAt', 'ASC')
            ->setParameter('workspace', $workspace)
            ->getQuery()
            ->getResult();
    }

    public function findDmBetween(User $user1, User $user2): ?Channel
    {
        $isSelf = $user1->getId() === $user2->getId();
        $targetCount = $isSelf ? 1 : 2;

        $qb = $this
            ->createQueryBuilder('c')
            ->join('c.members', 'm')
            ->where('c.isDm = true')
            ->andWhere('m.id IN (:userIds)')
            ->setParameter('userIds', array_unique([$user1->getId(), $user2->getId()]))
            ->groupBy('c.id')
            ->having('COUNT(m.id) = :targetCount')
            ->setParameter('targetCount', $targetCount)
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function searchByName(string $query, User $user, int $limit = 5): array
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
            $this->buildGroupAccessConditions($qb, $providerGroupIdentifiers, 'c'),
        );

        $results = $qb
            ->setParameter('query', '%' . strtolower($query) . '%')
            ->setParameter('userId', $user->getId())
            ->andWhere($accessConditions)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $unique = [];
        foreach ($results as $ch) {
            $unique[$ch->getId()] = $ch;
        }

        return array_values($unique);
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

    /**
     * @return Channel[]
     */
    public function searchAccessibleChannelsForUser(User $user, string $query = '', int $limit = 20): array
    {
        $providerGroups = $this->groupProvider->getGroupsForUser($user);
        $providerGroupIdentifiers = array_map(static fn($g) => $g->identifier, $providerGroups);

        $qb = $this
            ->createQueryBuilder('c')
            ->leftJoin('c.members', 'm')
            ->where('c.isDm = false')
            ->andWhere('c.parentMessage IS NULL');

        $accessConditions = $qb->expr()->orX(
            'c.isPrivate = false',
            'm.id = :userId',
            $this->buildGroupAccessConditions($qb, $providerGroupIdentifiers, 'c'),
        );

        $qb->andWhere($accessConditions)->setParameter('userId', $user->getId());

        if ($query !== '') {
            $qb->andWhere('LOWER(c.name) LIKE :q OR LOWER(c.slug) LIKE :q')->setParameter(
                'q',
                '%' . mb_strtolower($query) . '%',
            );
        }

        $results = $qb->orderBy('LOWER(c.name)', 'ASC')->setMaxResults($limit)->getQuery()->getResult();

        $unique = [];
        foreach ($results as $ch) {
            $unique[$ch->getId()] = $ch;
        }

        return array_values($unique);
    }

    /**
     * @param list<string> $providerGroupIdentifiers
     */
    private function buildWorkspaceAccessConditions(
        \Doctrine\ORM\QueryBuilder $qb,
        array $providerGroupIdentifiers,
    ): \Doctrine\ORM\Query\Expr\Orx {
        $directWorkspaceDql = $this
            ->getEntityManager()
            ->createQueryBuilder()
            ->select('w2.id')
            ->from(\App\Entity\Workspace::class, 'w2')
            ->join('w2.members', 'wm')
            ->where('wm.id = :userId')
            ->getDQL();

        $localGroupWorkspaceDql = $this
            ->getEntityManager()
            ->createQueryBuilder()
            ->select('w3.id')
            ->from(\App\Entity\Workspace::class, 'w3')
            ->join('w3.userGroup', 'ug3')
            ->join('ug3.members', 'ugm3')
            ->where('ugm3.id = :userId')
            ->getDQL();

        $conditions = $qb->expr()->orX(
            $qb->expr()->in('w.id', $directWorkspaceDql),
            $qb->expr()->in('w.id', $localGroupWorkspaceDql),
        );

        if ($providerGroupIdentifiers !== []) {
            $externalGroupWorkspaceDql = $this
                ->getEntityManager()
                ->createQueryBuilder()
                ->select('w4.id')
                ->from(\App\Entity\Workspace::class, 'w4')
                ->join('w4.userGroup', 'ug4')
                ->where('ug4.groupIdentifier IN (:providerGroupIdentifiers)')
                ->getDQL();

            $conditions->add($qb->expr()->in('w.id', $externalGroupWorkspaceDql));
            $qb->setParameter('providerGroupIdentifiers', $providerGroupIdentifiers);
        }

        return $conditions;
    }

    /**
     * @param list<string> $providerGroupIdentifiers
     */
    private function buildGroupAccessConditions(
        \Doctrine\ORM\QueryBuilder $qb,
        array $providerGroupIdentifiers,
        string $channelAlias = 'c',
    ): \Doctrine\ORM\Query\Expr\Orx {
        $localGroupDql = $this
            ->getEntityManager()
            ->createQueryBuilder()
            ->select('IDENTITY(gs_local.channel)')
            ->from(GroupSubscription::class, 'gs_local')
            ->join(UserGroup::class, 'ug_local', 'WITH', 'ug_local.groupIdentifier = gs_local.groupIdentifier')
            ->join('ug_local.members', 'ugm_local')
            ->where('ugm_local.id = :userId')
            ->getDQL();

        $conditions = $qb->expr()->orX($qb->expr()->in($channelAlias . '.id', $localGroupDql));

        if ($providerGroupIdentifiers !== []) {
            $externalGroupDql = $this
                ->getEntityManager()
                ->createQueryBuilder()
                ->select('IDENTITY(gs_ext.channel)')
                ->from(GroupSubscription::class, 'gs_ext')
                ->where('gs_ext.groupIdentifier IN (:providerGroupIdentifiers)')
                ->getDQL();

            $conditions->add($qb->expr()->in($channelAlias . '.id', $externalGroupDql));
            $qb->setParameter('providerGroupIdentifiers', $providerGroupIdentifiers);
        }

        return $conditions;
    }

    public function findOneByNameOrSlugFuzzy(string $query): ?Channel
    {
        $normalized = strtolower(trim($query));
        if ($normalized === '') {
            return null;
        }

        // 1. Exact match by slug or name (case-insensitive)
        $exact = $this
            ->createQueryBuilder('c')
            ->where('LOWER(c.slug) = :query OR LOWER(c.name) = :query')
            ->setParameter('query', $normalized)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($exact !== null) {
            return $exact;
        }

        // 2. Partial match by name or slug
        return $this
            ->createQueryBuilder('c')
            ->where('LOWER(c.name) LIKE :like OR LOWER(c.slug) LIKE :like')
            ->setParameter('like', '%' . addcslashes($normalized, '%_') . '%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
