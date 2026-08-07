<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Tool\AiToolInterface;

class ConfirmationFakeTool implements AiToolInterface
{
    public bool $executed = false;
    public ?int $lastAuthorUserId = null;

    public function getName(): string
    {
        return 'confirm_tool';
    }

    public function getDescription(): string
    {
        return 'A fake side-effect tool that requires confirmation.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'channelSlug' => ['type' => 'string'],
            ],
            'required' => ['channelSlug'],
        ];
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public function __invoke(string $channelSlug, ?int $authorUserId = null, ?int $workspaceId = null): string
    {
        $this->executed = true;
        $this->lastAuthorUserId = $authorUserId;

        return 'Side-effect done';
    }
}
