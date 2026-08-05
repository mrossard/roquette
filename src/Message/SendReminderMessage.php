<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SendReminderMessage
{
    public function __construct(
        public int $reminderId,
    ) {}
}
