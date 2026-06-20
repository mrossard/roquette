<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    /**
     * @return PushSubscription[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }

    public function findOneByUserAndEndpoint(User $user, string $endpoint): ?PushSubscription
    {
        return $this->findOneBy(['user' => $user, 'endpoint' => $endpoint]);
    }
}
