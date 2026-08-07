<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Mutable state shared between a message handler and the tool-runner
 * generator closures while an LLM query is being processed.
 */
final class ToolRunState
{
    public ?string $pendingConfirmation = null;

    public int $toolsExecuted = 0;
}
