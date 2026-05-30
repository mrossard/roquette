<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Channel>
 */
class ChannelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Channel::class);
    }

    public function findAllForUser(\App\Entity\User $user): array
    {
        $joinedChannels = $this
            ->createQueryBuilder('c')
            ->join('c.members', 'm')
            ->where('m.id = :userId')
            ->setParameter('userId', $user->getId())
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        if (empty($joinedChannels)) {
            $general = $this->findOneBy(['slug' => 'general']);
            if ($general) {
                $general->addMember($user);
                $this->getEntityManager()->flush();
                $joinedChannels[] = $general;
            }
        }

        // Apply custom channel ordering if it exists
        $order = $user->getChannelOrder();
        if (!empty($order)) {
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

                return $a->getCreatedAt() <=> $b->getCreatedAt() ?: $a->getId() <=> $b->getId();
            });
        }

        return $joinedChannels;
    }

    /** @return Channel[] */
    public function findAllPublic(): array
    {
        return $this->findBy(['isPrivate' => false], ['name' => 'ASC']);
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
        return $this
            ->createQueryBuilder('c')
            ->leftJoin('c.members', 'm')
            ->where('c.isDm = false')
            ->andWhere('LOWER(c.name) LIKE :query OR LOWER(c.description) LIKE :query')
            ->andWhere('c.isPrivate = false OR m.id = :userId')
            ->setParameter('query', '%' . strtolower($query) . '%')
            ->setParameter('userId', $user->getId())
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();
    }
}
