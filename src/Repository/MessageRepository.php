<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Returns messages in a channel that are unread (id > lastReadMessageId, author != user).
     *
     * @return Message[]
     */
    public function findUnreadInChannel(
        Channel $channel,
        User $user,
        ?int $lastReadMessageId,
    ): array {
        $qb = $this
            ->createQueryBuilder('m')
            ->where('m.channel = :channel')
            ->andWhere('m.author != :user')
            ->orderBy('m.createdAt', 'ASC')
            ->setParameter('channel', $channel)
            ->setParameter('user', $user);

        if ($lastReadMessageId !== null) {
            $qb
                ->andWhere('m.id > :lastRead')
                ->setParameter('lastRead', $lastReadMessageId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Message[]
     */
    public function searchInChannel(Channel $channel, string $query): array
    {
        return $this
            ->createQueryBuilder('m')
            ->where('m.channel = :channel')
            ->andWhere('LOWER(m.content) LIKE :query')
            ->setParameter('channel', $channel)
            ->setParameter('query', '%' . mb_strtolower($query, 'UTF-8') . '%')
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finds the latest messages in a channel, eager loading the author, reactions, and reaction users.
     *
     * @return Message[]
     */
    public function findLatestInChannel(Channel $channel, int $limit = 50, ?int $beforeId = null): array
    {
        $qb = $this
            ->createQueryBuilder('m')
            ->select(
                'm',
                'author',
                'reactions',
                'reaction_user',
                'poll',
                'poll_options',
                'poll_votes',
                'poll_vote_user',
            )
            ->leftJoin('m.author', 'author')
            ->leftJoin('m.reactions', 'reactions')
            ->leftJoin('reactions.user', 'reaction_user')
            ->leftJoin('m.poll', 'poll')
            ->leftJoin('poll.options', 'poll_options')
            ->leftJoin('poll_options.votes', 'poll_votes')
            ->leftJoin('poll_votes.user', 'poll_vote_user')
            ->where('m.channel = :channel');

        if ($beforeId !== null) {
            $qb->andWhere('m.id < :beforeId')->setParameter('beforeId', $beforeId);
        }

        $qb->orderBy('m.id', 'DESC')
            ->setParameter('channel', $channel)
            ->setMaxResults($limit);

        $paginator = new Paginator($qb->getQuery());

        return iterator_to_array($paginator);
    }

    /**
     * Advanced global search for messages across all joined channels.
     *
     * @return Message[]
     */
    public function searchGlobal(
        User $currentUser,
        ?string $authorUsername = null,
        ?string $channelName = null,
        ?bool $hasFile = null,
        ?string $fileType = null,
        ?string $textQuery = null,
    ): array {
        $qb = $this
            ->createQueryBuilder('m')
            ->select('m', 'author', 'channel')
            ->join('m.author', 'author')
            ->join('m.channel', 'channel')
            ->leftJoin('channel.members', 'chanMember')
            ->where('channel.isPrivate = false OR chanMember = :currentUser')
            ->setParameter('currentUser', $currentUser);

        if ($authorUsername) {
            $qb->andWhere(
                'LOWER(author.username) = :authorUsername OR LOWER(author.displayName) = :authorUsername',
            )->setParameter('authorUsername', strtolower($authorUsername));
        }

        if ($channelName) {
            $qb->andWhere('LOWER(channel.name) = :channelName OR LOWER(channel.slug) = :channelName')->setParameter(
                'channelName',
                strtolower($channelName),
            );
        }

        if ($hasFile) {
            $qb->andWhere('m.fileName IS NOT NULL');
        }

        if ($fileType) {
            if ($fileType === 'pdf') {
                $qb->andWhere('m.mimeType = :fileType')->setParameter('fileType', 'application/pdf');
            } else {
                $qb->andWhere('m.mimeType LIKE :fileType')->setParameter('fileType', $fileType . '/%');
            }
        }

        if ($textQuery && trim($textQuery) !== '') {
            $qb->andWhere('LOWER(m.content) LIKE :textQuery')->setParameter(
                'textQuery',
                '%' . mb_strtolower($textQuery, 'UTF-8') . '%',
            );
        }

        return $qb->orderBy('m.createdAt', 'DESC')->setMaxResults(30)->getQuery()->getResult();
    }

    /**
     * @return Message[]
     */
    public function findMessagesAround(Channel $channel, int $messageId, int $limit = 50): array
    {
        $qb = $this
            ->createQueryBuilder('m')
            ->select(
                'm',
                'author',
                'reactions',
                'reaction_user',
                'poll',
                'poll_options',
                'poll_votes',
                'poll_vote_user',
            )
            ->leftJoin('m.author', 'author')
            ->leftJoin('m.reactions', 'reactions')
            ->leftJoin('reactions.user', 'reaction_user')
            ->leftJoin('m.poll', 'poll')
            ->leftJoin('poll.options', 'poll_options')
            ->leftJoin('poll_options.votes', 'poll_votes')
            ->leftJoin('poll_votes.user', 'poll_vote_user')
            ->where('m.channel = :channel')
            ->andWhere('m.id >= :minId')
            ->setParameter('channel', $channel)
            ->setParameter('minId', max(1, $messageId - 5))
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit);

        $paginator = new Paginator($qb->getQuery());

        return iterator_to_array($paginator);
    }
}
