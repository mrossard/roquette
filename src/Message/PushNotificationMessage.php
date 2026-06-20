<?php

declare(strict_types=1);

namespace App\Message;

final class PushNotificationMessage
{
    public function __construct(
        private readonly int $userId,
        private readonly string $title,
        private readonly string $body,
        private readonly string $url,
    ) {}

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getUrl(): string
    {
        return $this->url;
    }
}
