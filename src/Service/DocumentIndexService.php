<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Handles persistence, vectorization, and excerpt extraction for message document chunks.
 */
class DocumentIndexService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageRepository $messageRepository,
        private readonly DocumentTextExtractor $documentTextExtractor,
        #[Autowire(service: 'ai.vectorizer.doc_vectorizer')]
        private readonly ?VectorizerInterface $vectorizer = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Index an attached document into message_document_chunk.
     */
    public function indexDocument(int $messageId): bool
    {
        $message = $this->messageRepository->find($messageId);
        if (!$message || $message->getFilePath() === null) {
            $this->deleteDocumentChunks($messageId);

            return false;
        }

        $filePath = $message->getFilePath();
        $mimeType = $message->getMimeType();
        $fileName = $message->getFileName() ?? pathinfo($filePath, PATHINFO_BASENAME);

        if (!$this->documentTextExtractor->isSupported($mimeType, $fileName)) {
            $this->deleteDocumentChunks($messageId);

            return false;
        }

        $text = $this->documentTextExtractor->extractText($filePath, $mimeType, $fileName);
        if ($text === null || trim($text) === '') {
            $this->deleteDocumentChunks($messageId);

            return false;
        }

        $chunks = $this->documentTextExtractor->chunkText($text);
        if ($chunks === []) {
            $this->deleteDocumentChunks($messageId);

            return false;
        }

        $channelId = $message->getChannel()?->getId();
        if ($channelId === null) {
            return false;
        }

        $this->deleteDocumentChunks($messageId);

        $success = true;
        foreach ($chunks as $index => $chunk) {
            if ($this->insertDocumentChunk($messageId, (int) $channelId, $fileName, $index, $chunk)) {
                continue;
            }

            $success = false;
        }

        return $success;
    }

    public function insertDocumentChunk(
        int $messageId,
        int $channelId,
        string $fileName,
        int $index,
        string $chunk,
    ): bool {
        $vectorString = $this->tryGenerateVector($chunk);
        $conn = $this->entityManager->getConnection();

        try {
            if ($vectorString !== null) {
                $conn->executeStatement(
                    'INSERT INTO message_document_chunk (message_id, channel_id, file_name, chunk_index, content, embedding, created_at)
                     VALUES (:messageId, :channelId, :fileName, :chunkIndex, :content, :embedding::vector, NOW())',
                    [
                        'messageId' => $messageId,
                        'channelId' => $channelId,
                        'fileName' => $fileName,
                        'chunkIndex' => $index,
                        'content' => $chunk,
                        'embedding' => $vectorString,
                    ],
                );

                return true;
            }

            $conn->executeStatement(
                'INSERT INTO message_document_chunk (message_id, channel_id, file_name, chunk_index, content, embedding, created_at)
                 VALUES (:messageId, :channelId, :fileName, :chunkIndex, :content, NULL, NOW())',
                [
                    'messageId' => $messageId,
                    'channelId' => $channelId,
                    'fileName' => $fileName,
                    'chunkIndex' => $index,
                    'content' => $chunk,
                ],
            );

            return true;
        } catch (\Throwable $e) {
            $this->logger?->warning('Failed to insert document chunk {index} for message {id}: {error}', [
                'index' => $index,
                'id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Remove all document chunks for a message.
     */
    public function deleteDocumentChunks(int $messageId): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement('DELETE FROM message_document_chunk WHERE message_id = :messageId', [
            'messageId' => $messageId,
        ]);
    }

    /**
     * Retrieves the best matching document chunk excerpt for a given message and search query.
     */
    public function getMatchingDocumentExcerpt(int $messageId, string $query, int $maxLength = 300): ?string
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return null;
        }

        $conn = $this->entityManager->getConnection();
        $vectorString = $this->tryGenerateVector($trimmed);

        try {
            if ($vectorString !== null) {
                $sql = <<<SQL
                        SELECT content
                        FROM message_document_chunk
                        WHERE message_id = :messageId
                          AND embedding IS NOT NULL
                        ORDER BY (embedding <=> :queryVector::vector) ASC
                        LIMIT 1
                    SQL;
                $content = $conn->fetchOne($sql, [
                    'messageId' => $messageId,
                    'queryVector' => $vectorString,
                ]);

                if ($content !== false && $content !== null) {
                    return $this->formatExcerpt((string) $content, $trimmed, $maxLength);
                }
            }

            // FTS fallback
            $sql = <<<SQL
                    SELECT content
                    FROM message_document_chunk
                    WHERE message_id = :messageId
                      AND search_vector @@ websearch_to_tsquery('french', :ftsQuery)
                    ORDER BY ts_rank_cd(search_vector, websearch_to_tsquery('french', :ftsQuery)) DESC
                    LIMIT 1
                SQL;
            $content = $conn->fetchOne($sql, [
                'messageId' => $messageId,
                'ftsQuery' => $trimmed,
            ]);

            if ($content !== false && $content !== null) {
                return $this->formatExcerpt((string) $content, $trimmed, $maxLength);
            }

            // Default to first chunk
            $sql = 'SELECT content FROM message_document_chunk WHERE message_id = :messageId ORDER BY chunk_index ASC LIMIT 1';
            $content = $conn->fetchOne($sql, ['messageId' => $messageId]);
            if ($content !== false && $content !== null) {
                return $this->formatExcerpt((string) $content, $trimmed, $maxLength);
            }
        } catch (\Throwable $e) {
            $this->logger?->debug('Failed to get document excerpt for message {id}: {error}', [
                'id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function tryGenerateVector(string $text): ?string
    {
        if (!$this->vectorizer) {
            return null;
        }

        try {
            $vector = $this->vectorizer->vectorize($text);
            $vectorData = $vector->getData();

            return '[' . implode(',', $vectorData) . ']';
        } catch (\Throwable $e) {
            $this->logger?->debug('Document vectorization skipped: {error}', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function formatExcerpt(string $content, string $query, int $maxLength): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($content)) ?? '';
        if (mb_strlen($clean, 'UTF-8') <= $maxLength) {
            return $clean;
        }

        $pos = mb_stripos($clean, $query, 0, 'UTF-8');
        if ($pos === false) {
            return mb_substr($clean, 0, $maxLength, 'UTF-8') . '...';
        }

        $start = max(0, $pos - (int) ($maxLength / 3));
        $excerpt = mb_substr($clean, $start, $maxLength, 'UTF-8');

        if ($start > 0) {
            $excerpt = '...' . $excerpt;
        }
        if (($start + $maxLength) < mb_strlen($clean, 'UTF-8')) {
            $excerpt .= '...';
        }

        return $excerpt;
    }
}
