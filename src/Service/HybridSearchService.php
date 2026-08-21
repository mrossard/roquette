<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class HybridSearchService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageRepository $messageRepository,
        #[Autowire(service: 'ai.vectorizer.doc_vectorizer')]
        private readonly ?VectorizerInterface $vectorizer = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Index a message's content into pgvector for semantic search.
     */
    public function indexMessage(int $messageId): bool
    {
        $message = $this->messageRepository->find($messageId);
        if (!$message) {
            return false;
        }

        $content = $message->getContent();
        if ($content === null || trim($content) === '' || $message->isPoll()) {
            $this->deleteMessageEmbedding($messageId);

            return false;
        }

        if (!$this->vectorizer) {
            return false;
        }

        try {
            $vector = $this->vectorizer->vectorize($content);
            $vectorData = $vector->getData();
            $vectorString = '[' . implode(',', $vectorData) . ']';

            $conn = $this->entityManager->getConnection();
            $conn->executeStatement(
                'INSERT INTO message_embedding (message_id, channel_id, embedding, created_at)
                 VALUES (:messageId, :channelId, :embedding::vector, NOW())
                 ON CONFLICT (message_id) DO UPDATE
                 SET embedding = EXCLUDED.embedding, channel_id = EXCLUDED.channel_id',
                [
                    'messageId' => $message->getId(),
                    'channelId' => $message->getChannel()?->getId(),
                    'embedding' => $vectorString,
                ],
            );

            return true;
        } catch (\Throwable $e) {
            $this->logger?->warning('Failed to vectorize message {id}: {error}', [
                'id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Remove message embedding from database.
     */
    public function deleteMessageEmbedding(int $messageId): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement('DELETE FROM message_embedding WHERE message_id = :messageId', [
            'messageId' => $messageId,
        ]);
    }

    /**
     * Search in a specific channel using Hybrid RRF (or FTS fallback).
     *
     * @return Message[]
     */
    public function searchInChannel(Channel $channel, string $query, int $limit = 50): array
    {
        $trimmedQuery = trim($query);
        if ($trimmedQuery === '') {
            return [];
        }

        $vectorString = $this->tryGenerateQueryVector($trimmedQuery);
        $conn = $this->entityManager->getConnection();

        $ids = [];
        if ($vectorString !== null) {
            $ids = $this->executeHybridRrfInChannel(
                $conn,
                (int) $channel->getId(),
                $trimmedQuery,
                $vectorString,
                $limit,
            );
        }

        if ($ids === []) {
            $ids = $this->executeFtsInChannel($conn, (int) $channel->getId(), $trimmedQuery, $limit);
        }

        if ($ids === []) {
            // Graceful fallback to ILIKE if FTS returned nothing (e.g. stopwords or specific punctuation)
            return $this->messageRepository->searchInChannel($channel, $query, $limit);
        }

        return $this->hydrateMessagesByIds($ids);
    }

    /**
     * Advanced global hybrid search across all accessible channels.
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
        $trimmedQuery = $textQuery !== null ? trim($textQuery) : null;

        // If no text query, fallback directly to metadata filtering in repository
        if ($trimmedQuery === null || $trimmedQuery === '') {
            return $this->messageRepository->searchGlobal(
                currentUser: $currentUser,
                authorUsername: $authorUsername,
                channelName: $channelName,
                hasFile: $hasFile,
                fileType: $fileType,
                textQuery: null,
                limit: $limit,
            );
        }

        $vectorString = $this->tryGenerateQueryVector($trimmedQuery);
        $conn = $this->entityManager->getConnection();

        $ids = [];
        if ($vectorString !== null) {
            $ids = $this->executeHybridRrfGlobal(
                conn: $conn,
                userId: (int) $currentUser->getId(),
                textQuery: $trimmedQuery,
                vectorString: $vectorString,
                authorUsername: $authorUsername,
                channelName: $channelName,
                hasFile: $hasFile,
                fileType: $fileType,
                limit: $limit,
            );
        }

        if ($ids === []) {
            $ids = $this->executeFtsGlobal(
                conn: $conn,
                userId: (int) $currentUser->getId(),
                textQuery: $trimmedQuery,
                authorUsername: $authorUsername,
                channelName: $channelName,
                hasFile: $hasFile,
                fileType: $fileType,
                limit: $limit,
            );
        }

        if ($ids === []) {
            // Fallback to ILIKE if FTS was too restrictive
            return $this->messageRepository->searchGlobal(
                currentUser: $currentUser,
                authorUsername: $authorUsername,
                channelName: $channelName,
                hasFile: $hasFile,
                fileType: $fileType,
                textQuery: $textQuery,
                limit: $limit,
            );
        }

        return $this->hydrateMessagesByIds($ids);
    }

    private function tryGenerateQueryVector(string $query): ?string
    {
        if (!$this->vectorizer) {
            return null;
        }

        try {
            $vector = $this->vectorizer->vectorize($query);
            $vectorData = $vector->getData();

            return '[' . implode(',', $vectorData) . ']';
        } catch (\Throwable $e) {
            $this->logger?->debug('Hybrid search vectorization skipped: {error}', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return int[]
     */
    private function executeHybridRrfInChannel(
        Connection $conn,
        int $channelId,
        string $textQuery,
        string $vectorString,
        int $limit,
    ): array {
        $candidateLimit = max(20, $limit * 2);

        $sql = <<<SQL
                WITH fts_results AS (
                    SELECT m.id,
                           ROW_NUMBER() OVER (
                               ORDER BY ts_rank_cd(m.search_vector, websearch_to_tsquery('french', :ftsQuery)) DESC,
                                        m.created_at DESC
                           ) AS rank
                    FROM "message" m
                    WHERE m.channel_id = :channelId
                      AND m.search_vector @@ websearch_to_tsquery('french', :ftsQuery)
                    LIMIT :candidateLimit
                ),
                vector_results AS (
                    SELECT me.message_id AS id,
                           ROW_NUMBER() OVER (
                               ORDER BY me.embedding <=> :queryVector::vector ASC
                           ) AS rank
                    FROM message_embedding me
                    WHERE me.channel_id = :channelId
                    LIMIT :candidateLimit
                )
                SELECT COALESCE(f.id, v.id) AS id,
                       (COALESCE(1.0 / (60.0 + f.rank), 0.0) + COALESCE(1.0 / (60.0 + v.rank), 0.0)) AS rrf_score
                FROM fts_results f
                FULL OUTER JOIN vector_results v ON f.id = v.id
                ORDER BY rrf_score DESC
                LIMIT :limit
            SQL;

        try {
            $rows = $conn->fetchAllAssociative($sql, [
                'channelId' => $channelId,
                'ftsQuery' => $textQuery,
                'queryVector' => $vectorString,
                'candidateLimit' => $candidateLimit,
                'limit' => $limit,
            ]);

            return array_map('intval', array_column($rows, 'id'));
        } catch (\Throwable $e) {
            $this->logger?->warning('Hybrid RRF in channel failed: {error}', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return int[]
     */
    private function executeFtsInChannel(Connection $conn, int $channelId, string $textQuery, int $limit): array
    {
        $sql = <<<SQL
                SELECT m.id
                FROM "message" m
                WHERE m.channel_id = :channelId
                  AND m.search_vector @@ websearch_to_tsquery('french', :ftsQuery)
                ORDER BY ts_rank_cd(m.search_vector, websearch_to_tsquery('french', :ftsQuery)) DESC, m.created_at DESC
                LIMIT :limit
            SQL;

        try {
            $rows = $conn->fetchAllAssociative($sql, [
                'channelId' => $channelId,
                'ftsQuery' => $textQuery,
                'limit' => $limit,
            ]);

            return array_map('intval', array_column($rows, 'id'));
        } catch (\Throwable $e) {
            $this->logger?->warning('FTS in channel failed: {error}', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return int[]
     */
    private function executeHybridRrfGlobal(
        Connection $conn,
        int $userId,
        string $textQuery,
        string $vectorString,
        ?string $authorUsername,
        ?string $channelName,
        ?bool $hasFile,
        ?string $fileType,
        int $limit,
    ): array {
        $candidateLimit = max(30, $limit * 2);

        $whereFilterFts = '';
        $whereFilterVec = '';
        $params = [
            'userId' => $userId,
            'ftsQuery' => $textQuery,
            'queryVector' => $vectorString,
            'candidateLimit' => $candidateLimit,
            'limit' => $limit,
        ];

        if ($authorUsername !== null && $authorUsername !== '') {
            $whereFilterFts .= ' AND (LOWER(u.username) = :authorUsername OR LOWER(u.display_name) = :authorUsername)';
            $whereFilterVec .= ' AND (LOWER(u.username) = :authorUsername OR LOWER(u.display_name) = :authorUsername)';
            $params['authorUsername'] = mb_strtolower($authorUsername, 'UTF-8');
        }

        if ($channelName !== null && $channelName !== '') {
            $whereFilterFts .= ' AND (LOWER(ch.name) = :channelName OR LOWER(ch.slug) = :channelName)';
            $whereFilterVec .= ' AND (LOWER(ch.name) = :channelName OR LOWER(ch.slug) = :channelName)';
            $params['channelName'] = mb_strtolower($channelName, 'UTF-8');
        }

        if ($hasFile) {
            $whereFilterFts .= ' AND m.file_name IS NOT NULL';
            $whereFilterVec .= ' AND m.file_name IS NOT NULL';
        }

        if ($fileType !== null && $fileType !== '') {
            $isPdf = $fileType === 'pdf';
            $whereFilterFts .= $isPdf ? ' AND m.mime_type = :fileType' : ' AND m.mime_type LIKE :fileType';
            $whereFilterVec .= $isPdf ? ' AND m.mime_type = :fileType' : ' AND m.mime_type LIKE :fileType';
            $params['fileType'] = $isPdf ? 'application/pdf' : $fileType . '/%';
        }

        $sql = <<<SQL
                WITH fts_results AS (
                    SELECT m.id,
                           ROW_NUMBER() OVER (
                               ORDER BY ts_rank_cd(m.search_vector, websearch_to_tsquery('french', :ftsQuery)) DESC,
                                        m.created_at DESC
                           ) AS rank
                    FROM "message" m
                    JOIN "channel" ch ON ch.id = m.channel_id
                    JOIN "user" u ON u.id = m.author_id
                    LEFT JOIN channel_user cu ON cu.channel_id = ch.id AND cu.user_id = :userId
                    WHERE (ch.is_private = false OR cu.user_id IS NOT NULL)
                      AND m.search_vector @@ websearch_to_tsquery('french', :ftsQuery)
                      {$whereFilterFts}
                    LIMIT :candidateLimit
                ),
                vector_results AS (
                    SELECT me.message_id AS id,
                           ROW_NUMBER() OVER (
                               ORDER BY me.embedding <=> :queryVector::vector ASC
                           ) AS rank
                    FROM message_embedding me
                    JOIN "message" m ON m.id = me.message_id
                    JOIN "channel" ch ON ch.id = me.channel_id
                    JOIN "user" u ON u.id = m.author_id
                    LEFT JOIN channel_user cu ON cu.channel_id = ch.id AND cu.user_id = :userId
                    WHERE (ch.is_private = false OR cu.user_id IS NOT NULL)
                      {$whereFilterVec}
                    LIMIT :candidateLimit
                )
                SELECT COALESCE(f.id, v.id) AS id,
                       (COALESCE(1.0 / (60.0 + f.rank), 0.0) + COALESCE(1.0 / (60.0 + v.rank), 0.0)) AS rrf_score
                FROM fts_results f
                FULL OUTER JOIN vector_results v ON f.id = v.id
                ORDER BY rrf_score DESC
                LIMIT :limit
            SQL;

        try {
            $rows = $conn->fetchAllAssociative($sql, $params);

            return array_map('intval', array_column($rows, 'id'));
        } catch (\Throwable $e) {
            $this->logger?->warning('Hybrid RRF Global failed: {error}', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return int[]
     */
    private function executeFtsGlobal(
        Connection $conn,
        int $userId,
        string $textQuery,
        ?string $authorUsername,
        ?string $channelName,
        ?bool $hasFile,
        ?string $fileType,
        int $limit,
    ): array {
        $whereFilter = '';
        $params = [
            'userId' => $userId,
            'ftsQuery' => $textQuery,
            'limit' => $limit,
        ];

        if ($authorUsername !== null && $authorUsername !== '') {
            $whereFilter .= ' AND (LOWER(u.username) = :authorUsername OR LOWER(u.display_name) = :authorUsername)';
            $params['authorUsername'] = mb_strtolower($authorUsername, 'UTF-8');
        }

        if ($channelName !== null && $channelName !== '') {
            $whereFilter .= ' AND (LOWER(ch.name) = :channelName OR LOWER(ch.slug) = :channelName)';
            $params['channelName'] = mb_strtolower($channelName, 'UTF-8');
        }

        if ($hasFile) {
            $whereFilter .= ' AND m.file_name IS NOT NULL';
        }

        if ($fileType !== null && $fileType !== '') {
            $isPdf = $fileType === 'pdf';
            $whereFilter .= $isPdf ? ' AND m.mime_type = :fileType' : ' AND m.mime_type LIKE :fileType';
            $params['fileType'] = $isPdf ? 'application/pdf' : $fileType . '/%';
        }

        $sql = <<<SQL
                SELECT m.id
                FROM "message" m
                JOIN "channel" ch ON ch.id = m.channel_id
                JOIN "user" u ON u.id = m.author_id
                LEFT JOIN channel_user cu ON cu.channel_id = ch.id AND cu.user_id = :userId
                WHERE (ch.is_private = false OR cu.user_id IS NOT NULL)
                  AND m.search_vector @@ websearch_to_tsquery('french', :ftsQuery)
                  {$whereFilter}
                ORDER BY ts_rank_cd(m.search_vector, websearch_to_tsquery('french', :ftsQuery)) DESC, m.created_at DESC
                LIMIT :limit
            SQL;

        try {
            $rows = $conn->fetchAllAssociative($sql, $params);

            return array_map('intval', array_column($rows, 'id'));
        } catch (\Throwable $e) {
            $this->logger?->warning('FTS Global failed: {error}', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param int[] $ids
     * @return Message[]
     */
    private function hydrateMessagesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $messages = $this->messageRepository
            ->createQueryBuilder('m')
            ->select('m', 'author', 'channel', 'poll')
            ->join('m.author', 'author')
            ->join('m.channel', 'channel')
            ->leftJoin('m.poll', 'poll')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        // Preserve ranking order from RRF/FTS
        $messageMap = [];
        foreach ($messages as $msg) {
            $messageMap[$msg->getId()] = $msg;
        }

        $ordered = [];
        foreach ($ids as $id) {
            $msg = $messageMap[$id] ?? null;
            if ($msg === null) {
                continue;
            }

            $ordered[] = $msg;
        }

        return $ordered;
    }
}
