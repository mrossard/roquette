<?php

declare(strict_types=1);

namespace App\Ai;

use App\Service\DocChunker;
use Psr\Log\LoggerInterface;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\RetrieverInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Builds the documentation context injected into the assistant system prompt.
 *
 * Uses the vector store (RAG) first and falls back to chunked sections of
 * DOC_UTILISATEUR.md when retrieval fails or returns nothing.
 */
final class DocumentContextBuilder
{
    /** @var list<TextDocument>|null */
    private ?array $documents = null;

    public function __construct(
        private RetrieverInterface $retriever,
        private DocChunker $docChunker,
        private LoggerInterface $logger,
        private ParameterBagInterface $parameterBag,
    ) {}

    public function buildContext(string $question, int $limit = 5): string
    {
        try {
            $chunks = [];
            foreach ($this->retriever->retrieve($question, ['limit' => $limit]) as $doc) {
                if (!$doc->getMetadata()->hasText()) {
                    continue;
                }

                $title = $doc->getMetadata()->hasTitle() ? $doc->getMetadata()->getTitle() : $doc->getId();
                $chunks[] = '### ' . $title . "\n" . $doc->getMetadata()->getText();
            }

            if ($chunks !== []) {
                return implode("\n\n---\n\n", $chunks);
            }
        } catch (\Exception $e) {
            $this->logger->warning('RAG retrieval failed, falling back to DOC_UTILISATEUR.md', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->buildFallbackContext($limit);
    }

    private function buildFallbackContext(int $limit): string
    {
        if ($this->documents === null) {
            $this->documents = $this->docChunker->chunk((string) $this->parameterBag->get('kernel.project_dir'));
        }

        $chunks = [];
        foreach ($this->documents as $doc) {
            if (count($chunks) >= $limit) {
                break;
            }

            if (!$doc->getMetadata()->hasText()) {
                continue;
            }

            $title = $doc->getMetadata()->hasTitle() ? $doc->getMetadata()->getTitle() : $doc->getId();
            $chunks[] = '### ' . $title . "\n" . $doc->getMetadata()->getText();
        }

        return implode("\n\n---\n\n", $chunks);
    }
}
