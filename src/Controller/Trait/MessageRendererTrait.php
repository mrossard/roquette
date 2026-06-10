<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use App\Entity\Message;

/**
 * Provides a helper to build the _feed_item.html.twig parameter array
 * from a Message entity, avoiding copy-pasting the same 12-key array
 * in every action that renders a feed item.
 *
 * Usage: add `use MessageRendererTrait;` inside any AbstractController subclass.
 */
trait MessageRendererTrait
{
    /**
     * Renders the _feed_item.html.twig partial for a given Message and returns
     * the resulting HTML string (for embedding in Mercure payloads or HTMX responses).
     */
    private function renderFeedItem(Message $message, array $extraParams = []): string
    {
        return $this->renderView(
            'dashboard/_feed_item.html.twig',
            array_merge(
                $this->feedItemParams($message),
                $extraParams,
            ),
        );
    }

    /**
     * Returns the parameter array expected by _feed_item.html.twig.
     * Use this when you need to call render() instead of renderView().
     */
    private function feedItemParams(Message $message): array
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
