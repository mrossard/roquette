<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ChannelNotificationMessage
{
    public function __construct(
        private int $channelId,
        private int $messageId,
        private int $authorId,
        private string $title,
        private string $body,
        private string $url,
    ) {}

    public function getChannelId(): int
    {
        return $this->channelId;
    }

    public function getMessageId(): int
    {
        return $this->messageId;
    }

    public function getAuthorId(): int
    {
        return $this->authorId;
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
