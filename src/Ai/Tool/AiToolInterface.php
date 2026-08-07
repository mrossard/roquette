<?php

declare(strict_types=1);

namespace App\Ai\Tool;

interface AiToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /**
     * @return array<string, mixed> JSON Schema describing the tool parameters (object + properties + required).
     */
    public function getParametersSchema(): array;

    /**
     * Whether executing this tool has side effects and must be confirmed by the
     * user before running. Tools returning true are paused by the ToolRunner,
     * which asks the user to confirm the action via a signed confirmation button.
     */
    public function requiresConfirmation(): bool;
}
