<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\User;
use App\Entity\UserChannelRead;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserChannelRead>
 */
class UserChannelReadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserChannelRead::class);
    }

    /**
     * Get unread message counts for all channels for a given user.
     * Returns an array mapping channelId to unread message count.
     *
     * @param User $user
     * @return array<int, int>
     */
    public function getUnreadCounts(User $user): array
    {
        $mentionPattern = '%@' . strtolower($user->getUsername()) . '%';

        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb
            ->select(
                'c.id as channelId, c.isDm as isDm, COUNT(m.id) as unreadCount, SUM(CASE WHEN LOWER(m.content) LIKE :mentionPattern THEN 1 ELSE 0 END) as mentionCount, ucr.notificationsEnabled as notificationsEnabled',
            )
            ->from(Channel::class, 'c')
            ->leftJoin(UserChannelRead::class, 'ucr', 'WITH', 'ucr.channel = c AND ucr.user = :user')
            ->leftJoin(
                'c.messages',
                'm',
                'WITH',
                'm.author != :user AND (ucr.lastReadMessage IS NULL OR m.id > IDENTITY(ucr.lastReadMessage))',
            )
            ->groupBy('c.id', 'c.isDm', 'ucr.notificationsEnabled')
            ->setParameter('user', $user)
            ->setParameter('mentionPattern', $mentionPattern);

        $results = $qb->getQuery()->getResult();

        $counts = [];
        foreach ($results as $row) {
            $notificationsEnabled = $row['notificationsEnabled'];
            if ($notificationsEnabled === null) {
                $notificationsEnabled = (bool) $row['isDm'];
            }
            $counts[(int) $row['channelId']] = [
                'count' => (int) $row['unreadCount'],
                'hasMention' => (int) $row['mentionCount'] > 0,
                'notificationsEnabled' => (bool) $notificationsEnabled,
            ];
        }

        return $counts;
    }

    /**
     * Batch-load UserChannelRead for all members of a channel in one query.
     *
     * @param Collection<int, User>|User[] $members
     * @return array<int, UserChannelRead> indexed by user id
     */
    public function findByChannelAndUsers(Channel $channel, Collection|array $members): array
    {
        $result = $this
            ->createQueryBuilder('ucr')
            ->where('ucr.channel = :channel')
            ->andWhere('ucr.user IN (:users)')
            ->setParameter('channel', $channel)
            ->setParameter('users', $members)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($result as $ucr) {
            $indexed[$ucr->getUser()->getId()] = $ucr;
        }

        return $indexed;
    }
}
