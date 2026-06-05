<?php

declare(strict_types=1);

namespace App\Message;

final class LlmQueryMessage
{
    public function __construct(
        private readonly string $question,
        private readonly int $userId,
        private readonly string $channelSlug,
        private readonly string $helpMessageId,
    ) {}

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
}
