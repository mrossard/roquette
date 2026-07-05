<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\KanbanColumn;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KanbanColumn>
 */
class KanbanColumnRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanColumn::class);
    }

    /**
     * @return KanbanColumn[]
     */
    public function findByChannelOrdered(Channel $channel): array
    {
        return $this
            ->createQueryBuilder('kc')
            ->where('kc.channel = :channel')
            ->setParameter('channel', $channel)
            ->orderBy('kc.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getNextPosition(Channel $channel): int
    {
        $result = $this
            ->createQueryBuilder('kc')
            ->select('MAX(kc.position)')
            ->where('kc.channel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? (int) $result + 1 : 0;
    }

    /**
     * @return KanbanColumn[]
     */
    public function findByChannelWithMessages(Channel $channel): array
    {
        return $this
            ->createQueryBuilder('kc')
            ->leftJoin('kc.messages', 'm')
            ->addSelect('m')
            ->leftJoin('m.author', 'a')
            ->addSelect('a')
            ->leftJoin('m.assignedTo', 'at')
            ->addSelect('at')
            ->leftJoin('m.reactions', 'r')
            ->addSelect('r')
            ->where('kc.channel = :channel')
            ->setParameter('channel', $channel)
            ->orderBy('kc.position', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
