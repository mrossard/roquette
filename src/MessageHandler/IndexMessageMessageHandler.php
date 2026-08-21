<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\IndexMessageMessage;
use App\Service\HybridSearchService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class IndexMessageMessageHandler
{
    public function __construct(
        private HybridSearchService $hybridSearchService,
        private ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(IndexMessageMessage $message): void
    {
        $this->logger?->info('Indexing message for vector search', ['messageId' => $message->messageId]);
        $this->hybridSearchService->indexMessage($message->messageId);
    }
}
