<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Message;

class PublishResult
{
    public function __construct(
        public bool $success,
        public ?Channel $channel = null,
        public ?Message $message = null,
        public ?string $error = null,
        public ?int $statusCode = null,
        public ?string $renderedHtml = null,
    ) {}

    public static function ok(
        Channel $channel,
        ?Message $message = null,
        ?string $renderedHtml = null,
    ): self {
        return new self(
            success: true,
            channel: $channel,
            message: $message,
            renderedHtml: $renderedHtml,
        );
    }

    public static function error(
        string $error,
        ?Channel $channel = null,
        int $statusCode = 400,
    ): self {
        return new self(
            success: false,
            channel: $channel,
            error: $error,
            statusCode: $statusCode,
        );
    }

    public static function empty(Channel $channel): self
    {
        return new self(
            success: false,
            channel: $channel,
        );
    }
}
