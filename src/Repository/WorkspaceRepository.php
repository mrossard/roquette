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
    public function __construct(
        ManagerRegistry $registry,
        private readonly \App\Service\Group\GroupProviderInterface $groupProvider,
    ) {
        parent::__construct($registry, Workspace::class);
    }

    /** @return Workspace[] */
    public function findAllForUser(User $user): array
    {
        $providerGroups = $this->groupProvider->getGroupsForUser($user);
        $providerGroupIdentifiers = array_map(static fn($g) => $g->identifier, $providerGroups);

        $qb = $this->createQueryBuilder('w')
            ->leftJoin('w.userGroup', 'ug')
            ->addSelect('ug');

        $conditions = $qb->expr()->orX(
            $qb->expr()->isMemberOf(':userId', 'w.members'),
            $qb->expr()->eq('w.isPublic', 'true')
        );

        // Local group membership DQL
        $localGroupDql = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('w2.id')
            ->from(Workspace::class, 'w2')
            ->join('w2.userGroup', 'ug2')
            ->join('ug2.members', 'ugm')
            ->where('ugm.id = :userId')
            ->getDQL();

        $conditions->add($qb->expr()->in('w.id', $localGroupDql));

        if (!empty($providerGroupIdentifiers)) {
            $conditions->add($qb->expr()->in('ug.groupIdentifier', ':providerGroupIdentifiers'));
            $qb->setParameter('providerGroupIdentifiers', $providerGroupIdentifiers);
        }

        return $qb
            ->where($conditions)
            ->setParameter('userId', $user->getId())
            ->orderBy('w.name', 'ASC')
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
