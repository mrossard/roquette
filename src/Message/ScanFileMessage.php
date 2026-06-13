<?php

declare(strict_types=1);

namespace App\Message;

class ScanFileMessage
{
    public function __construct(
        private readonly int $messageId,
    ) {}

    public function getMessageId(): int
    {
        return $this->messageId;
    }
}
