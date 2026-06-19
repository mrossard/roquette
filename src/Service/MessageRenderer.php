<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Message;
use Twig\Environment;

class MessageRenderer
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    public function renderFeedItem(Message $message, array $extraParams = []): string
    {
        return $this->twig->render('dashboard/_feed_item.html.twig', array_merge(
            $this->feedItemParams($message),
            $extraParams,
        ));
    }

    public function feedItemParams(Message $message): array
    {
        return [
            'author' => $message->getAuthor(),
            'message' => $message->getContent(),
            'timestamp' => $message->getCreatedAt(),
            'message_id' => $message->getId(),
            'updated_at' => $message->getUpdatedAt(),
            'fileName' => $message->getFileName(),
            'fileSize' => $message->getFileSize(),
            'filePath' => $message->getFilePath(),
            'mimeType' => $message->getMimeType(),
            'messageObject' => $message,
        ];
    }
}
