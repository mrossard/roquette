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
        private readonly ?DocumentIndexService $documentIndexService = null,
    ) {}

    /**
     * Index a message's content and its attached document into pgvector for semantic search.
     */
    public function indexMessage(int $messageId): bool
    {
        $message = $this->messageRepository->find($messageId);
        if (!$message) {
            return false;
        }

        $indexedContent = $this->indexMessageContent($message);
        $indexedDoc = $this->indexMessageDocument($message);

        return $indexedContent || $indexedDoc;
    }

    public function indexDocument(int $messageId): bool
    {
        return $this->documentIndexService?->indexDocument($messageId) ?? false;
    }

    public function deleteDocumentChunks(int $messageId): void
    {
        $this->documentIndexService?->deleteDocumentChunks($messageId);
    }

    public function deleteMessageEmbedding(int $messageId): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement('DELETE FROM message_embedding WHERE message_id = :messageId', [
            'messageId' => $messageId,
        ]);
        $this->deleteDocumentChunks($messageId);
    }

    public function getMatchingDocumentExcerpt(int $messageId, string $query, int $maxLength = 300): ?string
    {
        return $this->documentIndexService?->getMatchingDocumentExcerpt($messageId, $query, $maxLength);
    }

    private function indexMessageContent(Message $message): bool
    {
        $messageId = (int) $message->getId();
        $content = $message->getContent();
        $conn = $this->entityManager->getConnection();

        if ($content === null || trim($content) === '' || $message->isPoll() || $this->vectorizer === null) {
            $conn->executeStatement('DELETE FROM message_embedding WHERE message_id = :messageId', [
                'messageId' => $messageId,
            ]);

            return false;
        }

        try {
            $vector = $this->vectorizer->vectorize($content);
            $vectorData = $vector->getData();
            $vectorString = '[' . implode(',', $vectorData) . ']';

            $conn->executeStatement(
                'INSERT INTO message_embedding (message_id, channel_id, embedding, created_at)
                 VALUES (:messageId, :channelId, :embedding::vector, NOW())
                 ON CONFLICT (message_id) DO UPDATE
                 SET embedding = EXCLUDED.embedding, channel_id = EXCLUDED.channel_id',
                [
                    'messageId' => $messageId,
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

    private function indexMessageDocument(Message $message): bool
    {
        $messageId = (int) $message->getId();
        if ($message->getFilePath() === null) {
            $this->deleteDocumentChunks($messageId);

            return false;
        }

        return $this->indexDocument($messageId);
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
                WITH fts_candidates AS (
                    SELECT m.id,
                           ts_rank_cd(m.search_vector, websearch_to_tsquery('french', :ftsQuery)) AS score,
                           m.created_at
                    FROM "message" m
                    WHERE m.channel_id = :channelId
                      AND m.search_vector @@ websearch_to_tsquery('french', :ftsQuery)
                    UNION ALL
                    SELECT mdc.message_id AS id,
                           ts_rank_cd(mdc.search_vector, websearch_to_tsquery('french', :ftsQuery)) AS score,
                           mdc.created_at
                    FROM message_document_chunk mdc
                    WHERE mdc.channel_id = :channelId
                      AND mdc.search_vector @@ websearch_to_tsquery('french', :ftsQuery)
                ),
                fts_aggregated AS (
                    SELECT id, MAX(score) AS max_score, MAX(created_at) AS created_at
                    FROM fts_candidates
                    GROUP BY id
                ),
                fts_results AS (
                    SELECT id,
                           ROW_NUMBER() OVER (
                               ORDER BY max_score DESC, created_at DESC
                           ) AS rank
                    FROM fts_aggregated
                    LIMIT :candidateLimit
                ),
                vector_candidates AS (
                    SELECT me.message_id AS id,
                           (me.embedding <=> :queryVector::vector) AS distance
                    FROM message_embedding me
                    WHERE me.channel_id = :channelId
                    UNION ALL
                    SELECT mdc.message_id AS id,
                           (mdc.embedding <=> :queryVector::vector) AS distance
                    FROM message_document_chunk mdc
                    WHERE mdc.channel_id = :channelId
                      AND mdc.embedding IS NOT NULL
                ),
                vector_aggregated AS (
                    SELECT id, MIN(distance) AS min_distance
                    FROM vector_candidates
                    GROUP BY id
                ),
                vector_results AS (
                    SELECT id,
                           ROW_NUMBER() OVER (
                               ORDER BY min_distance ASC
                           ) AS rank
                    FROM vector_aggregated
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
                WITH fts_candidates AS (
                    SELECT m.id,
                           ts_rank_cd(m.search_vector, websearch_to_tsquery('french', :ftsQuery)) AS score,
                           m.created_at
                    FROM "message" m
                    WHERE m.channel_id = :channelId
                      AND m.search_vector @@ websearch_to_tsquery('french', :ftsQuery)
                    UNION ALL
                    SELECT mdc.message_id AS id,
                           ts_rank_cd(mdc.search_vector, websearch_to_tsquery('french', :ftsQuery)) AS score,
                           mdc.created_at
                    FROM message_document_chunk mdc
                    WHERE mdc.channel_id = :channelId
                      AND mdc.search_vector @@ websearch_to_tsquery('french', :ftsQuery)
                )
                SELECT id
                FROM fts_candidates
                GROUP BY id
                ORDER BY MAX(score) DESC, MAX(created_at) DESC
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
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilterClauses(
        ?string $authorUsername,
        ?string $channelName,
        ?bool $hasFile,
        ?string $fileType,
    ): array {
        $filterSql = '';
        $params = [];

        if ($authorUsername !== null && $authorUsername !== '') {
            $filterSql .= ' AND (LOWER(u.username) = :authorUsername OR LOWER(u.display_name) = :authorUsername)';
            $params['authorUsername'] = mb_strtolower($authorUsername, 'UTF-8');
        }

        if ($channelName !== null && $channelName !== '') {
            $filterSql .= ' AND (LOWER(ch.name) = :channelName OR LOWER(ch.slug) = :channelName)';
            $params['channelName'] = mb_strtolower($channelName, 'UTF-8');
        }

        if ($hasFile) {
            $filterSql .= ' AND m.file_name IS NOT NULL';
        }

        if ($fileType !== null && $fileType !== '') {
            $isPdf = $fileType === 'pdf';
            $filterSql .= $isPdf ? ' AND m.mime_type = :fileType' : ' AND m.mime_type LIKE :fileType';
            $params['fileType'] = $isPdf ? 'application/pdf' : $fileType . '/%';
        }

        return [$filterSql, $params];
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
        [$whereFilter, $filterParams] = $this->buildFilterClauses($authorUsername, $channelName, $hasFile, $fileType);

        $params = array_merge([
            'userId' => $userId,
            'ftsQuery' => $textQuery,
            'queryVector' => $vectorString,
            'candidateLimit' => $candidateLimit,
            'limit' => $limit,
        ], $filterParams);

        $sql = <<<SQL
                WITH fts_candidates AS (
                    SELECT m.id,
                           ts_rank_cd(m.search_vector, websearch_to_tsquery('french', :ftsQuery)) AS score,
                           m.created_at
                    FROM "message" m
                    JOIN "channel" ch ON ch.id = m.channel_id
                    JOIN "user" u ON u.id = m.author_id
                    LEFT JOIN channel_user cu ON cu.channel_id = ch.id AND cu.user_id = :userId
                    WHERE (ch.is_private = false OR cu.user_id IS NOT NULL)
                      AND m.search_vector @@ websearch_to_tsquery('french', :ftsQuery)
                      {$whereFilter}
                    UNION ALL
                    SELECT mdc.message_id AS id,
                           ts_rank_cd(mdc.search_vector, websearch_to_tsquery('french', :ftsQuery)) AS score,
                           mdc.created_at
                    FROM message_document_chunk mdc
                    JOIN "message" m ON m.id = mdc.message_id
                    JOIN "channel" ch ON ch.id = mdc.channel_id
                    JOIN "user" u ON u.id = m.author_id
                    LEFT JOIN channel_user cu ON cu.channel_id = ch.id AND cu.user_id = :userId
                    WHERE (ch.is_private = false OR cu.user_id IS NOT NULL)
                      AND mdc.search_vector @@ websearch_to_tsquery('french', :ftsQuery)
                      {$whereFilter}
                ),
                fts_aggregated AS (
                    SELECT id, MAX(score) AS max_score, MAX(created_at) AS created_at
                    FROM fts_candidates
                    GROUP BY id
                ),
                fts_results AS (
                    SELECT id,
                           ROW_NUMBER() OVER (
                               ORDER BY max_score DESC, created_at DESC
                           ) AS rank
                    FROM fts_aggregated
                    LIMIT :candidateLimit
                ),
                vector_candidates AS (
                    SELECT me.message_id AS id,
                           (me.embedding <=> :queryVector::vector) AS distance
                    FROM message_embedding me
                    JOIN "message" m ON m.id = me.message_id
                    JOIN "channel" ch ON ch.id = me.channel_id
                    JOIN "user" u ON u.id = m.author_id
                    LEFT JOIN channel_user cu ON cu.channel_id = ch.id AND cu.user_id = :userId
                    WHERE (ch.is_private = false OR cu.user_id IS NOT NULL)
                      {$whereFilter}
                    UNION ALL
                    SELECT mdc.message_id AS id,
                           (mdc.embedding <=> :queryVector::vector) AS distance
                    FROM message_document_chunk mdc
                    JOIN "message" m ON m.id = mdc.message_id
                    JOIN "channel" ch ON ch.id = mdc.channel_id
                    JOIN "user" u ON u.id = m.author_id
                    LEFT JOIN channel_user cu ON cu.channel_id = ch.id AND cu.user_id = :userId
                    WHERE (ch.is_private = false OR cu.user_id IS NOT NULL)
                      AND mdc.embedding IS NOT NULL
                      {$whereFilter}
                ),
                vector_aggregated AS (
                    SELECT id, MIN(distance) AS min_distance
                    FROM vector_candidates
                    GROUP BY id
                ),
                vector_results AS (
                    SELECT id,
                           ROW_NUMBER() OVER (
                               ORDER BY min_distance ASC
                           ) AS rank
                    FROM vector_aggregated
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
        [$whereFilter, $filterParams] = $this->buildFilterClauses($authorUsername, $channelName, $hasFile, $fileType);

        $params = array_merge([
            'userId' => $userId,
            'ftsQuery' => $textQuery,
            'limit' => $limit,
        ], $filterParams);

        $sql = <<<SQL
                WITH fts_candidates AS (
                    SELECT m.id,
                           ts_rank_cd(m.search_vector, websearch_to_tsquery('french', :ftsQuery)) AS score,
                           m.created_at
                    FROM "message" m
                    JOIN "channel" ch ON ch.id = m.channel_id
                    JOIN "user" u ON u.id = m.author_id
                    LEFT JOIN channel_user cu ON cu.channel_id = ch.id AND cu.user_id = :userId
                    WHERE (ch.is_private = false OR cu.user_id IS NOT NULL)
                      AND m.search_vector @@ websearch_to_tsquery('french', :ftsQuery)
                      {$whereFilter}
                    UNION ALL
                    SELECT mdc.message_id AS id,
                           ts_rank_cd(mdc.search_vector, websearch_to_tsquery('french', :ftsQuery)) AS score,
                           mdc.created_at
                    FROM message_document_chunk mdc
                    JOIN "message" m ON m.id = mdc.message_id
                    JOIN "channel" ch ON ch.id = mdc.channel_id
                    JOIN "user" u ON u.id = m.author_id
                    LEFT JOIN channel_user cu ON cu.channel_id = ch.id AND cu.user_id = :userId
                    WHERE (ch.is_private = false OR cu.user_id IS NOT NULL)
                      AND mdc.search_vector @@ websearch_to_tsquery('french', :ftsQuery)
                      {$whereFilter}
                )
                SELECT id
                FROM fts_candidates
                GROUP BY id
                ORDER BY MAX(score) DESC, MAX(created_at) DESC
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
