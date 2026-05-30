<?php

namespace App\Repository;

use App\Entity\Invitation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    /** @return Invitation[] */
    public function findPendingForUser(User $user): array
    {
        return $this->findBy(
            ['invitee' => $user],
            ['createdAt' => 'DESC']
        );
    }
}
