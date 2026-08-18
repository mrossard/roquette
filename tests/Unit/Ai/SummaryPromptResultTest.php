<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\SummaryPromptResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SummaryPromptResultTest extends TestCase
{
    #[Test]
    public function requiresBatchingReturnsFalseWhenBatchesIsNull(): void
    {
        $result = new SummaryPromptResult('prompt', 'systemPrompt', null);
        $this->assertFalse($result->requiresBatching());
    }

    #[Test]
    public function requiresBatchingReturnsFalseWhenSingleBatch(): void
    {
        $result = new SummaryPromptResult('prompt', 'systemPrompt', [[[
            'date' => '2026-01-01',
            'auteur' => 'alice',
            'contenu' => 'hello',
        ]]]);
        $this->assertFalse($result->requiresBatching());
    }

    #[Test]
    public function requiresBatchingReturnsTrueWhenMultipleBatches(): void
    {
        $result = new SummaryPromptResult('prompt', 'systemPrompt', [
            [['date' => '2026-01-01', 'auteur' => 'alice', 'contenu' => 'hello']],
            [['date' => '2026-01-01', 'auteur' => 'bob', 'contenu' => 'world']],
        ]);
        $this->assertTrue($result->requiresBatching());
    }
}
