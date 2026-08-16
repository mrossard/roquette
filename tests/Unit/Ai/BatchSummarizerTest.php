<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\BatchSummarizer;
use App\Service\LlmService;
use PHPUnit\Framework\TestCase;

class BatchSummarizerTest extends TestCase
{
    public function testSummarizeCombinesBatchesAndReportsProgress(): void
    {
        $llmService = $this->createMock(LlmService::class);
        $llmService
            ->expects($this->exactly(2))
            ->method('generateText')
            ->willReturnOnConsecutiveCalls('Synthèse lot 1', 'Synthèse lot 2');

        $summarizer = new BatchSummarizer($llmService);

        $batches = [
            [['auteur' => 'Alice', 'contenu' => 'Bonjour']],
            [['auteur' => 'Bob', 'contenu' => 'Salut']],
        ];

        $progressCalls = [];
        $finalProgressCalled = false;

        [$prompt, $systemPrompt] = $summarizer->summarize(
            $batches,
            onBatchProgress: static function (int $batchNum, int $total) use (&$progressCalls): void {
                $progressCalls[] = [$batchNum, $total];
            },
            onFinalProgress: static function () use (&$finalProgressCalled): void {
                $finalProgressCalled = true;
            },
        );

        static::assertSame([[1, 2], [2, 2]], $progressCalls);
        static::assertTrue($finalProgressCalled);
        static::assertStringContainsString('--- Résumé du Lot 1 ---', $prompt);
        static::assertStringContainsString('Synthèse lot 1', $prompt);
        static::assertStringContainsString('--- Résumé du Lot 2 ---', $prompt);
        static::assertStringContainsString('Synthèse lot 2', $prompt);
        static::assertStringContainsString("Tu es 'Assistant Roquette'", $systemPrompt);
    }
}
