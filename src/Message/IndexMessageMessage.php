<?php

declare(strict_types=1);

namespace App\Message;

final readonly class IndexMessageMessage
{
    public function __construct(
        public int $messageId,
    ) {}
}
