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
    public function findUnreadInChannel(Channel $channel, User $user, ?int $lastReadMessageId): array
    {
        $qb = $this
            ->createQueryBuilder('m')
            ->where('m.channel = :channel')
            ->andWhere('m.author != :user')
            ->orderBy('m.createdAt', 'ASC')
            ->setParameter('channel', $channel)
            ->setParameter('user', $user);

        if ($lastReadMessageId !== null) {
            $qb->andWhere('m.id > :lastRead')->setParameter('lastRead', $lastReadMessageId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Message[]
     */
    public function searchInChannel(Channel $channel, string $query): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $ids = $conn->fetchFirstColumn(
            'SELECT m.id FROM "message" m
             WHERE m.channel_id = :channelId
               AND LOWER(m.content) LIKE :query
             ORDER BY m.created_at DESC',
            ['channelId' => $channel->getId(), 'query' => '%' . strtolower($query) . '%'],
        );

        if ($ids === []) {
            return [];
        }

        $ids = array_map('intval', $ids);

        return $this->createQueryBuilder('m')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
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

        $qb->orderBy('m.id', 'DESC')->setParameter('channel', $channel)->setMaxResults($limit);

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
        $conn = $this->getEntityManager()->getConnection();

        $conditions = ['(ch.is_private = false OR cu.user_id IS NOT NULL)'];
        $params = ['currentUserId' => $currentUser->getId()];

        if ($authorUsername) {
            $conditions[] = '(LOWER(u.username) = :authorUsername OR LOWER(u.display_name) = :authorUsername)';
            $params['authorUsername'] = strtolower($authorUsername);
        }

        if ($channelName) {
            $conditions[] = '(LOWER(ch.name) = :channelName OR LOWER(ch.slug) = :channelName)';
            $params['channelName'] = strtolower($channelName);
        }

        if ($hasFile) {
            $conditions[] = 'm.file_name IS NOT NULL';
        }

        if ($fileType) {
            if ($fileType === 'pdf') {
                $conditions[] = 'm.mime_type = :fileType';
                $params['fileType'] = 'application/pdf';
            } else {
                $conditions[] = 'm.mime_type LIKE :fileType';
                $params['fileType'] = $fileType . '/%';
            }
        }

        $orderBy = 'm.created_at DESC';
        if ($textQuery && trim($textQuery) !== '') {
            $conditions[] = 'LOWER(m.content) LIKE :textQuery';
            $params['textQuery'] = '%' . strtolower($textQuery) . '%';
            $orderBy = 'm.created_at DESC';
        }

        $where = implode(' AND ', $conditions);

        $ids = $conn->fetchFirstColumn(
            "SELECT m.id FROM \"message\" m
             JOIN \"user\" u ON u.id = m.author_id
             JOIN \"channel\" ch ON ch.id = m.channel_id
             LEFT JOIN channel_user cu ON cu.channel_id = ch.id AND cu.user_id = :currentUserId
             WHERE {$where}
             ORDER BY {$orderBy}
             LIMIT 30",
            $params,
        );

        if ($ids === []) {
            return [];
        }

        $ids = array_map('intval', $ids);

        return $this->createQueryBuilder('m')
            ->select('m', 'author', 'channel')
            ->join('m.author', 'author')
            ->join('m.channel', 'channel')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param int[] $messageIds
     *
     * @return array<int, int> messageId => reply count
     */
    public function findReplyCounts(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $qb = $this
            ->createQueryBuilder('m')
            ->select('IDENTITY(m.parentMessage) AS parent_id, COUNT(m.id) AS reply_count')
            ->where('m.parentMessage IN (:ids)')
            ->setParameter('ids', $messageIds)
            ->groupBy('m.parentMessage');

        $results = $qb->getQuery()->getScalarResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[(int) $row['parent_id']] = (int) $row['reply_count'];
        }

        return $counts;
    }

    /**
     * Finds all messages in the reply tree of a given message (including the message itself, ordered by creation date).
     *
     * @return Message[]
     */
    public function findReplyTree(Message $message): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = '
            WITH RECURSIVE reply_tree AS (
                SELECT id, parent_message_id, created_at
                FROM message
                WHERE parent_message_id = :messageId
                UNION ALL
                SELECT m.id, m.parent_message_id, m.created_at
                FROM message m
                INNER JOIN reply_tree rt ON m.parent_message_id = rt.id
            )
            SELECT id FROM reply_tree
            ORDER BY created_at ASC
        ';
        $ids = $conn->fetchFirstColumn($sql, ['messageId' => $message->getId()]);

        if ($ids === []) {
            return [];
        }

        $ids = array_map('intval', $ids);

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
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('m.createdAt', 'ASC');

        return $qb->getQuery()->getResult();
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

    /**
     * @return Message[]
     */
    public function findFilesByChannel(Channel $channel): array
    {
        return $this->createQueryBuilder('m')
            ->select('m', 'author')
            ->join('m.author', 'author')
            ->where('m.channel = :channel')
            ->andWhere('m.filePath IS NOT NULL')
            ->orderBy('m.id', 'DESC')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getResult();
    }
}

