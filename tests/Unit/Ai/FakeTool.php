<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Tool\AiToolInterface;

class FakeTool implements AiToolInterface
{
    public string $lastChannelSlug = '';
    public ?int $lastAuthorUserId = null;
    public ?int $lastWorkspaceId = null;

    public function getName(): string
    {
        return 'fake_tool';
    }

    public function getDescription(): string
    {
        return 'A fake tool for tests.';
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

    public function __invoke(string $channelSlug, ?int $authorUserId = null, ?int $workspaceId = null): string
    {
        $this->lastChannelSlug = $channelSlug;
        $this->lastAuthorUserId = $authorUserId;
        $this->lastWorkspaceId = $workspaceId;

        return 'Tool executed for ' . $channelSlug;
    }
}
