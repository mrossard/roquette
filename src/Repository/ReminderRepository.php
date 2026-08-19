<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Message;
use App\Entity\Reminder;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reminder>
 */
class ReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reminder::class);
    }

    /**
     * @return Reminder[]
     */
    public function findPendingByUser(User $user): array
    {
        return $this
            ->createQueryBuilder('r')
            ->leftJoin('r.targetMessage', 'm')
            ->addSelect('m')
            ->leftJoin('r.channel', 'c')
            ->addSelect('c')
            ->where('r.user = :user')
            ->andWhere('r.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'pending')
            ->orderBy('r.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findPendingForMessageAndUser(Message $message, User $user): ?Reminder
    {
        return $this
            ->createQueryBuilder('r')
            ->where('r.targetMessage = :message')
            ->andWhere('r.user = :user')
            ->andWhere('r.status = :status')
            ->setParameter('message', $message)
            ->setParameter('user', $user)
            ->setParameter('status', 'pending')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(Reminder $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }

    public function remove(Reminder $entity): void
    {
        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();
    }
}
