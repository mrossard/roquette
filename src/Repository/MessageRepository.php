<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     * Returns top-level messages that are unread (id > lastReadMessageId, author != user)
     * OR that have at least one unread reply (reply.id > lastReadMessageId, reply.author != user).
     *
     * @return Message[]
     */
    public function findUnreadInChannel(
        \App\Entity\Channel $channel,
        \App\Entity\User $user,
        ?int $lastReadMessageId,
    ): array {
        if ($lastReadMessageId === null) {
            // No read record at all: all messages from others are "unread"
            return $this
                ->createQueryBuilder('m')
                ->where('m.channel = :channel')
                ->andWhere('m.parent IS NULL')
                ->andWhere('m.author != :user')
                ->orderBy('m.createdAt', 'ASC')
                ->setParameter('channel', $channel)
                ->setParameter('user', $user)
                ->getQuery()
                ->getResult();
        }

        // Use a native EXISTS subquery to reliably detect parents with unread replies
        $em = $this->getEntityManager();

        $dql = '
            SELECT m FROM App\Entity\Message m
            WHERE m.channel = :channel
            AND m.parent IS NULL
            AND (
                (m.id > :lastRead AND m.author != :user)
                OR EXISTS (
                    SELECT r.id FROM App\Entity\Message r
                    WHERE r.parent = m
                    AND r.id > :lastRead
                    AND r.author != :user
                )
            )
            ORDER BY m.createdAt ASC
        ';

        return $em
            ->createQuery($dql)
            ->setParameter('channel', $channel)
            ->setParameter('user', $user)
            ->setParameter('lastRead', $lastReadMessageId)
            ->getResult();
    }

    /**
     * @return Message[]
     */
    public function searchInChannel(\App\Entity\Channel $channel, string $query): array
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
     * Finds the latest top-level messages in a channel, eager loading the author, reactions, and reaction users.
     *
     * @return Message[]
     */
    public function findLatestInChannel(\App\Entity\Channel $channel, int $limit = 50, ?int $beforeId = null): array
    {
        $qb = $this
            ->createQueryBuilder('m')
            ->select('m', 'author', 'reactions', 'reaction_user', 'replies')
            ->leftJoin('m.author', 'author')
            ->leftJoin('m.reactions', 'reactions')
            ->leftJoin('reactions.user', 'reaction_user')
            ->leftJoin('m.replies', 'replies')
            ->where('m.channel = :channel')
            ->andWhere('m.parent IS NULL');

        if ($beforeId !== null) {
            $qb->andWhere('m.id < :beforeId')->setParameter('beforeId', $beforeId);
        }

        return $qb
            ->orderBy('m.id', 'DESC')
            ->setParameter('channel', $channel)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Advanced global search for messages across all joined channels.
     *
     * @return Message[]
     */
    public function searchGlobal(
        \App\Entity\User $currentUser,
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
    public function findMessagesAround(\App\Entity\Channel $channel, int $messageId, int $limit = 50): array
    {
        return $this
            ->createQueryBuilder('m')
            ->select('m', 'author', 'reactions', 'reaction_user', 'replies')
            ->leftJoin('m.author', 'author')
            ->leftJoin('m.reactions', 'reactions')
            ->leftJoin('reactions.user', 'reaction_user')
            ->leftJoin('m.replies', 'replies')
            ->where('m.channel = :channel')
            ->andWhere('m.parent IS NULL')
            ->andWhere('m.id >= :minId')
            ->setParameter('channel', $channel)
            ->setParameter('minId', max(1, $messageId - 5))
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
