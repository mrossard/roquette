<?php

declare(strict_types=1);

namespace App\Message;

final readonly class GenerateExportMessage
{
    public function __construct(
        private int $channelId,
        private int $userId,
    ) {}

    public function getChannelId(): int
    {
        return $this->channelId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }
}
