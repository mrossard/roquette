<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Message;

class PublishResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?Channel $channel = null,
        public readonly ?Message $message = null,
        public readonly ?string $error = null,
        public readonly ?int $statusCode = null,
        public readonly ?string $renderedHtml = null,
    ) {}
}
