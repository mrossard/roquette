<?php

declare(strict_types=1);

namespace App\Ai;

final readonly class SummaryPromptResult
{
    /**
     * @param list<list<array{date: string, auteur: string, contenu: string}>>|null $batches
     */
    public function __construct(
        public string $prompt,
        public string $systemPrompt,
        public ?array $batches = null,
    ) {}

    public function requiresBatching(): bool
    {
        return $this->batches !== null && count($this->batches) > 1;
    }
}
