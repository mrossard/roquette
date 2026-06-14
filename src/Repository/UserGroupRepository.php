<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserGroup>
 */
class UserGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserGroup::class);
    }

    /**
     * @return UserGroup[]
     */
    public function findGroupsForUser(User $user): array
    {
        return $this->createQueryBuilder('g')
            ->join('g.members', 'm')
            ->where('m.id = :userId')
            ->setParameter('userId', $user->getId())
            ->getQuery()
            ->getResult();
    }

    /**
     * @return UserGroup[]
     */
    public function findAdministeredGroupsForUser(User $user): array
    {
        return $this->createQueryBuilder('g')
            ->join('g.administrators', 'a')
            ->where('a.id = :userId')
            ->setParameter('userId', $user->getId())
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAdministeredGroupsForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->join('g.administrators', 'a')
            ->where('a.id = :userId')
            ->setParameter('userId', $user->getId())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return UserGroup[]
     */
    public function findPaginatedAll(int $page, int $perPage = 25): array
    {
        return $this->createQueryBuilder('g')
            ->orderBy('g.name', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return UserGroup[]
     */
    public function findPaginatedAdministeredGroupsForUser(User $user, int $page, int $perPage = 25): array
    {
        return $this->createQueryBuilder('g')
            ->join('g.administrators', 'a')
            ->where('a.id = :userId')
            ->setParameter('userId', $user->getId())
            ->orderBy('g.name', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }
}
