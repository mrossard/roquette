<?php

declare(strict_types=1);

namespace App\Message;

use App\Ai\AssistantIntent;

final class LlmQueryMessage
{
    private readonly ?AssistantIntent $intent;

    public function __construct(
        private readonly string $question,
        private readonly int $userId,
        private readonly string $channelSlug,
        private readonly string $helpMessageId,
        AssistantIntent|string|null $intent = null,
        private readonly ?int $workspaceId = null,
    ) {
        $this->intent = is_string($intent) ? AssistantIntent::tryFrom($intent) : $intent;
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getChannelSlug(): string
    {
        return $this->channelSlug;
    }

    public function getHelpMessageId(): string
    {
        return $this->helpMessageId;
    }

    public function getIntent(): ?AssistantIntent
    {
        return $this->intent;
    }

    public function getWorkspaceId(): ?int
    {
        return $this->workspaceId;
    }
}
