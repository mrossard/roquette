<?php

declare(strict_types=1);

namespace App\Ai;

final readonly class LlmPromptBundle
{
    public function __construct(
        public string $prompt,
        public string $systemPrompt,
        public string $prefix = '',
        public int $batchCount = 0,
    ) {}
}
