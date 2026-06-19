<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Message;
use App\Entity\Reaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reaction>
 */
class ReactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reaction::class);
    }

    /**
     * @return array<int, Message>
     */
    public function findDistinctMessagesByUser(User $user, int $limit = 50, ?int $beforeId = null): array
    {
        $dql = 'SELECT m, c FROM App\Entity\Message m
             JOIN m.channel c
             JOIN App\Entity\Reaction r WITH r.message = m
             WHERE r.user = :user';

        $params = ['user' => $user];

        if ($beforeId !== null) {
            $dql .= ' AND m.id < :beforeId';
            $params['beforeId'] = $beforeId;
        }

        $dql .= ' GROUP BY m.id, c.id ORDER BY MAX(m.createdAt) DESC';

        return $this->getEntityManager()
            ->createQuery($dql)
            ->setParameters($params)
            ->setMaxResults($limit)
            ->getResult();
    }

    /**
     * @return array<int, Message>
     */
    public function findDistinctMessagesByUserAndEmoji(User $user, string $emoji, int $limit = 50, ?int $beforeId = null): array
    {
        $dql = 'SELECT m, c FROM App\Entity\Message m
             JOIN m.channel c
             JOIN App\Entity\Reaction r WITH r.message = m
             WHERE r.user = :user AND r.emoji = :emoji';

        $params = ['user' => $user, 'emoji' => $emoji];

        if ($beforeId !== null) {
            $dql .= ' AND m.id < :beforeId';
            $params['beforeId'] = $beforeId;
        }

        $dql .= ' GROUP BY m.id, c.id ORDER BY MAX(m.createdAt) DESC';

        return $this->getEntityManager()
            ->createQuery($dql)
            ->setParameters($params)
            ->setMaxResults($limit)
            ->getResult();
    }

    /**
     * @return list<string>
     */
    public function findUserEmojis(User $user): array
    {
        return $this
            ->createQueryBuilder('r')
            ->select('r.emoji')
            ->where('r.user = :user')
            ->groupBy('r.emoji')
            ->orderBy('COUNT(r.id)', 'DESC')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleColumnResult();
    }
}
