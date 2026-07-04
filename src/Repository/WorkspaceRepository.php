<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Workspace>
 */
class WorkspaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Workspace::class);
    }

    /** @return Workspace[] */
    public function findAllForUser(User $user): array
    {
        return $this
            ->createQueryBuilder('w')
            ->join('w.members', 'm')
            ->where('m.id = :userId')
            ->orderBy('w.name', 'ASC')
            ->setParameter('userId', $user->getId())
            ->getQuery()
            ->getResult();
    }

    public function findPublicWorkspace(): ?Workspace
    {
        return $this->findOneBy(['isPublic' => true]);
    }

    /** @return User[] */
    public function findMembersNotInWorkspace(Workspace $workspace, User $currentUser, string $query): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id != :currentUserId')
            ->andWhere('u NOT IN (
                SELECT m FROM App\Entity\User m
                JOIN m.workspaces w
                WHERE w.id = :workspaceId
            )')
            ->andWhere($qb->expr()->orX(
                $qb->expr()->like('LOWER(u.username)', ':query'),
                $qb->expr()->like('LOWER(u.displayName)', ':query'),
            ))
            ->setParameter('currentUserId', $currentUser->getId())
            ->setParameter('workspaceId', $workspace->getId())
            ->setParameter('query', '%' . strtolower($query) . '%');

        return $qb->getQuery()->getResult();
    }
}
