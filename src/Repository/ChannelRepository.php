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

        $general = $this->findOneBy(['slug' => 'general']);
        if (!$general) {
            $general = new Channel();
            $general->setName('Général');
            $general->setSlug('general');
            $general->setDescription('Le canal de discussion principal pour tout le monde.');
            $this->getEntityManager()->persist($general);
        }

        $isMemberOfGeneral = false;
        foreach ($joinedChannels as $channel) {
            if ($channel->getId() === $general->getId()) {
                $isMemberOfGeneral = true;
                break;
            }
        }

        if (!$isMemberOfGeneral) {
            $general->addMember($user);
            $this->getEntityManager()->flush();
            array_unshift($joinedChannels, $general);
        }

        $robotUser = $this
            ->getEntityManager()
            ->getRepository(\App\Entity\User::class)
            ->findOneBy(['username' => 'robot-roquette']);
        if (!$robotUser) {
            $robotUser = new \App\Entity\User();
            $robotUser->setUsername('robot-roquette');
            $robotUser->setDisplayName('Assistant');
            $robotUser->setPassword('robot-roquette-dummy-password');
            $robotUser->setRoles(['ROLE_USER']);
            $this->getEntityManager()->persist($robotUser);
            $this->getEntityManager()->flush();
        } else {
            if ($robotUser->getDisplayName() !== 'Assistant') {
                $robotUser->setDisplayName('Assistant');
                $this->getEntityManager()->flush();
            }
        }

        $robotSlug = 'dm-robot-roquette-'.$user->getSlug();
        $hasRobotChannel = false;
        foreach ($joinedChannels as $channel) {
            if ($channel->getSlug() === $robotSlug) {
                if ($channel->getName() !== 'Assistant') {
                    $channel->setName('Assistant');
                    $channel->setDescription('Discussion privée avec l\'Assistant');
                    $this->getEntityManager()->flush();
                }
                $hasRobotChannel = true;
                break;
            }
        }
        if (!$hasRobotChannel) {
            $robotChannel = $this->findOneBy(['slug' => $robotSlug]);
            if (!$robotChannel) {
                $robotChannel = new Channel();
                $robotChannel->setName('Assistant');
                $robotChannel->setSlug($robotSlug);
                $robotChannel->setDescription('Discussion privée avec l\'Assistant');
                $robotChannel->setIsPrivate(true);
                $robotChannel->setIsDm(true);
                $robotChannel->addMember($user);
                $robotChannel->addMember($robotUser);
                $this->getEntityManager()->persist($robotChannel);
                $this->getEntityManager()->flush();
            } else {
                $flushed = false;
                if ($robotChannel->getName() !== 'Assistant') {
                    $robotChannel->setName('Assistant');
                    $robotChannel->setDescription('Discussion privée avec l\'Assistant');
                    $flushed = true;
                }
                if (!$robotChannel->getMembers()->contains($user)) {
                    $robotChannel->addMember($user);
                    $flushed = true;
                }
                if (!$robotChannel->getMembers()->contains($robotUser)) {
                    $robotChannel->addMember($robotUser);
                    $flushed = true;
                }
                if ($flushed) {
                    $this->getEntityManager()->flush();
                }
            }
            $joinedChannels[] = $robotChannel;
        }

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
        return $this->findBy(['isPrivate' => false, 'parentMessage' => null], ['name' => 'ASC']);
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
            ->andWhere('c.parentMessage IS NULL')
            ->andWhere('LOWER(c.name) LIKE :query OR LOWER(c.description) LIKE :query')
            ->andWhere('c.isPrivate = false OR m.id = :userId')
            ->setParameter('query', '%' . strtolower($query) . '%')
            ->setParameter('userId', $user->getId())
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

        return (int)$count > 0;
    }
}
