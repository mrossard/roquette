<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(
        PasswordAuthenticatedUserInterface $user,
        #[\SensitiveParameter]
        string $newHashedPassword,
    ): void {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /** @return User[] */
    public function findAllExcept(User $user): array
    {
        return $this
            ->createQueryBuilder('u')
            ->where('u.id != :userId')
            ->setParameter('userId', $user->getId())
            ->orderBy('u.username', 'ASC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();
    }

    /** @return User[] */
    public function findMembersForWorkspace(\App\Entity\Workspace $workspace, User $exceptUser): array
    {
        return $this
            ->createQueryBuilder('u')
            ->join('u.workspaces', 'w')
            ->where('w.id = :workspaceId')
            ->andWhere('u.id != :exceptUserId')
            ->andWhere('u.username != :robot')
            ->setParameter('workspaceId', $workspace->getId())
            ->setParameter('exceptUserId', $exceptUser->getId())
            ->setParameter('robot', User::ROBOT_USERNAME)
            ->orderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return User[] Users not already members of $channel and without a pending invitation */
    public function findInvitableForChannel(Channel $channel, User $currentUser, ?string $searchQuery = null): array
    {
        $qb = $this
            ->createQueryBuilder('u')
            ->where('u.id != :currentUserId')
            ->andWhere('u.id NOT IN (
                SELECT mu.id FROM App\Entity\Channel c2 JOIN c2.members mu WHERE c2.id = :channelId
            )')
            ->andWhere('u.id NOT IN (
                SELECT IDENTITY(i.invitee) FROM App\Entity\Invitation i WHERE i.channel = :channelId
            )')
            ->setParameter('currentUserId', $currentUser->getId())
            ->setParameter('channelId', $channel->getId());

        if ($searchQuery !== null && $searchQuery !== '') {
            $qb->andWhere(
                'LOWER(u.username) LIKE :searchQuery OR LOWER(u.displayName) LIKE :searchQuery',
            )->setParameter('searchQuery', '%' . strtolower($searchQuery) . '%');
        }

        return $qb->setMaxResults(20)->getQuery()->getResult();
    }

    public function searchByName(string $query): array
    {
        return $this
            ->createQueryBuilder('u')
            ->where('LOWER(u.username) LIKE :query OR LOWER(u.displayName) LIKE :query')
            ->setParameter('query', '%' . strtolower($query) . '%')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();
    }

    public function getAllSortedByDisplayName(): iterable
    {
        return $this
            ->buildSortedByDisplayNameQuery()
            ->andWhere('u.username != :robot')
            ->setParameter('robot', User::ROBOT_USERNAME)
            ->getQuery()
            ->getResult();
    }

    public function getAllSortedByDisplayNameWithRobot(): iterable
    {
        return $this->buildSortedByDisplayNameQuery()->getQuery()->getResult();
    }

    private function buildSortedByDisplayNameQuery(): QueryBuilder
    {
        return $this
            ->createQueryBuilder('u')
            ->addSelect('COALESCE(u.displayName, u.username) AS HIDDEN sortName')
            ->orderBy('sortName', 'ASC');
    }

    public function countAll(): int
    {
        return (int) $this
            ->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.username != :robot')
            ->setParameter('robot', User::ROBOT_USERNAME)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAllWithRobot(): int
    {
        return (int) $this->createQueryBuilder('u')->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * @return User[]
     */
    public function findPaginated(int $page, int $perPage = 25): array
    {
        $page = max(1, min($page, 10_000));
        $perPage = max(1, min($perPage, 100));

        return $this
            ->buildSortedByDisplayNameQuery()
            ->andWhere('u.username != :robot')
            ->setParameter('robot', User::ROBOT_USERNAME)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return User[]
     */
    public function findPaginatedWithRobot(int $page, int $perPage = 25): array
    {
        $page = max(1, min($page, 10_000));
        $perPage = max(1, min($perPage, 100));

        return $this
            ->buildSortedByDisplayNameQuery()
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns all Users who have access to the given channel.
     *
     * @return User[]
     */
    public function getMembersForChannel(Channel $channel): array
    {
        $workspace = $channel->getWorkspace();
        if ($workspace !== null && !$channel->isPrivate()) {
            return $this
                ->createQueryBuilder('u')
                ->join('u.workspaces', 'w')
                ->where('w.id = :workspaceId')
                ->setParameter('workspaceId', $workspace->getId())
                ->getQuery()
                ->getResult();
        }

        return $channel->getMembers()->toArray();
    }

    /**
     * @return User[]
     */
    public function searchAutocomplete(string $query = '', int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('u');
        if ($query !== '') {
            $qb->where('LOWER(u.username) LIKE :q OR LOWER(u.displayName) LIKE :q')->setParameter(
                'q',
                '%' . mb_strtolower($query) . '%',
            );
        }

        return $qb->setMaxResults($limit)->getQuery()->getResult();
    }
}
