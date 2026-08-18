<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\ModerationStatus;
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
     * Returns messages in a channel that are unread (id > lastReadMessageId, author != user).
     *
     * @return Message[]
     */
    public function findUnreadInChannel(Channel $channel, User $user, ?int $lastReadMessageId): array
    {
        $qb = $this
            ->createQueryBuilder('m')
            ->select('m', 'poll')
            ->leftJoin('m.poll', 'poll')
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
    public function searchInChannel(Channel $channel, string $query, int $limit = 50): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $idQb = $conn
            ->createQueryBuilder()
            ->select('m.id')
            ->from('"message"', 'm')
            ->where('m.channel_id = :channelId')
            ->andWhere('LOWER(m.content) LIKE :query')
            ->orderBy('m.created_at', 'DESC')
            ->setMaxResults(max(1, min($limit, 100)))
            ->setParameter('channelId', $channel->getId())
            ->setParameter('query', '%' . mb_strtolower($query, 'UTF-8') . '%');

        $ids = array_map('intval', $idQb->fetchFirstColumn());

        if ($ids === []) {
            return [];
        }

        return $this
            ->createQueryBuilder('m')
            ->leftJoin('m.poll', 'poll')
            ->addSelect('poll')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the immediately preceding message in the channel (lightweight query without heavy joins).
     */
    public function findPreviousMessage(Channel $channel, int $beforeId): ?Message
    {
        return $this->createQueryBuilder('m')
            ->where('m.channel = :channel')
            ->andWhere('m.id < :beforeId')
            ->setParameter('channel', $channel)
            ->setParameter('beforeId', $beforeId)
            ->orderBy('m.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Finds the latest messages in a channel, eager loading the author, reactions, and reaction users.
     *
     * @return Message[]
     */
    public function findLatestInChannel(Channel $channel, int $limit = 50, ?int $beforeId = null): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $idQb = $conn
            ->createQueryBuilder()
            ->select('id')
            ->from('"message"')
            ->where('channel_id = :channelId')
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('channelId', $channel->getId());

        if ($beforeId !== null) {
            $idQb->andWhere('id < :beforeId')->setParameter('beforeId', $beforeId);
        }

        $ids = array_map('intval', $idQb->fetchFirstColumn());

        if ($ids === []) {
            return [];
        }

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
                'parent_message',
                'parent_author',
                'parent_poll',
            )
            ->leftJoin('m.author', 'author')
            ->leftJoin('m.parentMessage', 'parent_message')
            ->leftJoin('parent_message.author', 'parent_author')
            ->leftJoin('parent_message.poll', 'parent_poll')
            ->leftJoin('m.reactions', 'reactions')
            ->leftJoin('reactions.user', 'reaction_user')
            ->leftJoin('m.poll', 'poll')
            ->leftJoin('poll.options', 'poll_options')
            ->leftJoin('poll_options.votes', 'poll_votes')
            ->leftJoin('poll_votes.user', 'poll_vote_user')
            ->where('m.id IN (:ids)')
            ->orderBy('m.id', 'DESC')
            ->setParameter('ids', $ids);

        $messages = $qb->getQuery()->getResult();

        // Batch pre-fetch any parent messages that were lazy-proxy loaded (outside the current 50 batch)
        $missingParentIds = [];
        foreach ($messages as $m) {
            $parent = $m->getParentMessage();
            if ($parent !== null && $parent instanceof \Doctrine\Persistence\Proxy && !$parent->__isInitialized()) {
                $missingParentIds[] = $parent->getId();
            }
        }

        if ($missingParentIds !== []) {
            $this->createQueryBuilder('m')
                ->select('m', 'author', 'poll')
                ->leftJoin('m.author', 'author')
                ->leftJoin('m.poll', 'poll')
                ->where('m.id IN (:parentIds)')
                ->setParameter('parentIds', array_unique($missingParentIds))
                ->getQuery()
                ->getResult();
        }

        return $messages;
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
        int $limit = 30,
    ): array {
        $conn = $this->getEntityManager()->getConnection();
        $qb = $conn
            ->createQueryBuilder()
            ->select('m.id')
            ->from('"message"', 'm')
            ->join('m', '"user"', 'u', 'u.id = m.author_id')
            ->join('m', '"channel"', 'ch', 'ch.id = m.channel_id')
            ->leftJoin('ch', 'channel_user', 'cu', 'cu.channel_id = ch.id AND cu.user_id = :currentUserId')
            ->where('(ch.is_private = false OR cu.user_id IS NOT NULL)')
            ->setParameter('currentUserId', $currentUser->getId())
            ->orderBy('m.created_at', 'DESC')
            ->setMaxResults(max(1, min($limit, 100)));

        if ($authorUsername !== null && $authorUsername !== '') {
            $qb->andWhere('(LOWER(u.username) = :authorUsername OR LOWER(u.display_name) = :authorUsername)')
                ->setParameter('authorUsername', mb_strtolower($authorUsername, 'UTF-8'));
        }

        if ($channelName !== null && $channelName !== '') {
            $qb->andWhere('(LOWER(ch.name) = :channelName OR LOWER(ch.slug) = :channelName)')
                ->setParameter('channelName', mb_strtolower($channelName, 'UTF-8'));
        }

        if ($hasFile) {
            $qb->andWhere('m.file_name IS NOT NULL');
        }

        if ($fileType !== null && $fileType !== '') {
            $isPdf = $fileType === 'pdf';
            $qb->andWhere($isPdf ? 'm.mime_type = :fileType' : 'm.mime_type LIKE :fileType')
                ->setParameter('fileType', $isPdf ? 'application/pdf' : $fileType . '/%');
        }

        if ($textQuery !== null && trim($textQuery) !== '') {
            $qb->andWhere('LOWER(m.content) LIKE :textQuery')
                ->setParameter('textQuery', '%' . mb_strtolower(trim($textQuery), 'UTF-8') . '%');
        }

        $ids = array_map('intval', $qb->fetchFirstColumn());

        if ($ids === []) {
            return [];
        }

        return $this
            ->createQueryBuilder('m')
            ->select('m', 'author', 'channel', 'poll')
            ->join('m.author', 'author')
            ->join('m.channel', 'channel')
            ->leftJoin('m.poll', 'poll')
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
        $minId = max(1, $messageId - 5);

        $conn = $this->getEntityManager()->getConnection();
        $ids = $conn->fetchFirstColumn(
            'SELECT id FROM "message"
             WHERE channel_id = :channelId AND id >= :minId
             ORDER BY id ASC
             LIMIT :limit',
            ['channelId' => $channel->getId(), 'minId' => $minId, 'limit' => $limit],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        $ids = array_map('intval', $ids);

        if ($ids === []) {
            return [];
        }

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
            ->orderBy('m.id', 'ASC')
            ->setParameter('ids', $ids);

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Message[]
     */
    public function findFilesByChannel(Channel $channel, int $limit = 50, ?int $beforeId = null): array
    {
        $qb = $this
            ->createQueryBuilder('m')
            ->select('m', 'author')
            ->join('m.author', 'author')
            ->where('m.channel = :channel')
            ->andWhere('m.filePath IS NOT NULL')
            ->orderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('channel', $channel);

        if ($beforeId !== null) {
            $qb->andWhere('m.id < :beforeId')->setParameter('beforeId', $beforeId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Returns the latest message for each channel in a single query.
     *
     * @param int[] $channelIds
     *
     * @return array<int, Message> channelId => lastMessage
     */
    public function findLastMessagesForChannels(array $channelIds): array
    {
        if ($channelIds === []) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        $rows = $conn->fetchAllAssociative(
            'SELECT DISTINCT ON (m.channel_id) m.id
             FROM message m
             WHERE m.channel_id IN (:channelIds)
             ORDER BY m.channel_id, m.created_at DESC, m.id DESC',
            ['channelIds' => $channelIds],
            ['channelIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        $ids = array_map('intval', array_column($rows, 'id'));

        if ($ids === []) {
            return [];
        }

        $messages = $this
            ->createQueryBuilder('m')
            ->select('m', 'author', 'channel', 'poll')
            ->join('m.author', 'author')
            ->join('m.channel', 'channel')
            ->leftJoin('m.poll', 'poll')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($messages as $msg) {
            $indexed[$msg->getChannel()->getId()] = $msg;
        }

        return $indexed;
    }

    public function findLastMessageForChannel(Channel $channel): ?Message
    {
        return $this
            ->createQueryBuilder('m')
            ->select('m', 'author', 'poll')
            ->join('m.author', 'author')
            ->leftJoin('m.poll', 'poll')
            ->where('m.channel = :channel')
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults(1)
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Message[]
     */
    public function findSavedByUser(User $user, int $limit = 50, ?int $beforeId = null): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $qb = $conn
            ->createQueryBuilder()
            ->select('usm.message_id')
            ->from('user_saved_messages', 'usm')
            ->where('usm.user_id = :userId')
            ->orderBy('usm.message_id', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('userId', $user->getId());

        if ($beforeId !== null) {
            $qb->andWhere('usm.message_id < :beforeId')->setParameter('beforeId', $beforeId);
        }

        $ids = array_map('intval', $qb->fetchFirstColumn());

        if ($ids === []) {
            return [];
        }

        return $this
            ->createQueryBuilder('m')
            ->select('m', 'author', 'channel', 'poll')
            ->join('m.author', 'author')
            ->join('m.channel', 'channel')
            ->leftJoin('m.poll', 'poll')
            ->where('m.id IN (:ids)')
            ->orderBy('m.id', 'DESC')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /** @return int[] */
    public function findSavedMessageIdsForUser(User $user): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $ids = $conn->fetchFirstColumn(
            'SELECT message_id FROM user_saved_messages WHERE user_id = :userId',
            ['userId' => $user->getId()]
        );

        return array_map('intval', $ids);
    }

    /**
     * @return Message[]
     */
    public function findModeratedPaginated(int $page = 1, int $perPage = 25): array
    {
        return $this->createQueryBuilder('m')
            ->select('m', 'author', 'channel')
            ->join('m.author', 'author')
            ->join('m.channel', 'channel')
            ->where('m.moderationStatus IS NOT NULL')
            ->andWhere('m.moderationStatus != :cleanStatus')
            ->setParameter('cleanStatus', ModerationStatus::CLEAN->value)
            ->orderBy('m.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countPendingModeration(): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.moderationStatus IS NOT NULL')
            ->andWhere('m.moderationStatus != :cleanStatus')
            ->setParameter('cleanStatus', ModerationStatus::CLEAN->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Finds messages not assigned to any Kanban column (untriaged) in a channel,
     * eager loading author, assignedTo, and reactions.
     *
     * @return Message[]
     */
    public function findUntriagedByChannel(Channel $channel): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.author', 'a')
            ->addSelect('a')
            ->leftJoin('m.assignedTo', 'at')
            ->addSelect('at')
            ->leftJoin('m.reactions', 'r')
            ->addSelect('r')
            ->where('m.channel = :channel')
            ->andWhere('m.kanbanColumn IS NULL')
            ->setParameter('channel', $channel)
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Message[]
     */
    public function findRecentInChannel(Channel $channel, int $limit = 100): array
    {
        $messages = $this->createQueryBuilder('m')
            ->where('m.channel = :channel')
            ->orderBy('m.createdAt', 'DESC')
            ->setParameter('channel', $channel)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_reverse($messages);
    }

    /**
     * @return Message[]
     */
    public function findRecentReadBefore(Channel $channel, int $lastReadId, int $limit = 5): array
    {
        $messages = $this->createQueryBuilder('m')
            ->where('m.channel = :channel')
            ->andWhere('m.parentMessage IS NULL')
            ->andWhere('m.id <= :lastReadId')
            ->orderBy('m.id', 'DESC')
            ->setParameter('channel', $channel)
            ->setParameter('lastReadId', $lastReadId)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_reverse($messages);
    }
}

