<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

        return $qb->getQuery()->getResult();
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

    public function getAllSortedByDisplayName(bool $withRobot): iterable
    {
        $qb = $this
            ->createQueryBuilder('u')
            ->addSelect('COALESCE(u.displayName, u.username) AS HIDDEN sortName')
            ->orderBy('sortName', 'ASC');

        if (!$withRobot) {
            $qb->andWhere('u.username != :robot')->setParameter('robot', User::ROBOT_USERNAME);
        }

        return $qb->getQuery()->getResult();
    }

    public function countAll(bool $withRobot = false): int
    {
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)');

        if (!$withRobot) {
            $qb->andWhere('u.username != :robot')->setParameter('robot', User::ROBOT_USERNAME);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return User[]
     */
    public function findPaginated(int $page, int $perPage = 25, bool $withRobot = false): array
    {
        $qb = $this
            ->createQueryBuilder('u')
            ->addSelect('COALESCE(u.displayName, u.username) AS HIDDEN sortName')
            ->orderBy('sortName', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (!$withRobot) {
            $qb->andWhere('u.username != :robot')->setParameter('robot', User::ROBOT_USERNAME);
        }

        return $qb->getQuery()->getResult();
    }
}
