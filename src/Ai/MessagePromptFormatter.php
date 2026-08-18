<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Message;
use App\Entity\User;

class MessagePromptFormatter
{
    /**
     * Resolves the author's display name or username.
     */
    public function resolveAuthorName(Message $message): string
    {
        $author = $message->getAuthor();
        if ($author === null) {
            return User::ROBOT_USERNAME;
        }

        $displayName = $author->getDisplayName();
        if ($displayName !== null && trim($displayName) !== '') {
            return $displayName;
        }

        return $author->getUsername() ?? User::ROBOT_USERNAME;
    }

    /**
     * Formats a single message line for plain-text conversation prompts (e.g. "Alice: Bonjour").
     */
    public function formatLine(Message $message, int $maxLength = 500): string
    {
        $author = $this->resolveAuthorName($message);
        $content = $message->isPoll()
            ? '[Sondage] ' . ($message->getPoll()?->getQuestion() ?? '')
            : $message->getContent() ?? '';

        $truncated = mb_substr(trim($content), 0, $maxLength);

        return sprintf('%s: %s', $author, $truncated);
    }

    /**
     * Formats a single message line including timestamp (e.g. "[17/08 12:00] Alice: Bonjour").
     */
    public function formatLineWithDate(Message $message, int $maxLength = 500, string $dateFormat = 'd/m H:i'): string
    {
        $author = $this->resolveAuthorName($message);
        $content = $message->isPoll()
            ? '[Sondage] ' . ($message->getPoll()?->getQuestion() ?? '')
            : $message->getContent() ?? '';

        $truncated = mb_substr(trim($content), 0, $maxLength);
        $date = $message->getCreatedAt()->format($dateFormat);

        return sprintf('[%s] %s: %s', $date, $author, $truncated);
    }

    /**
     * Formats a search reference line with channel slug and jumpTo link.
     */
    public function formatSearchReference(Message $message, int $maxLength = 300): string
    {
        $author = $this->resolveAuthorName($message);
        $channelSlug = $message->getChannel()?->getSlug() ?? 'general';
        $messageId = (int) ($message->getId() ?? 0);
        $date = $message->getCreatedAt()->format('d/m/Y H:i');
        $content = mb_substr(trim($message->getContent() ?? ''), 0, $maxLength);

        $fileInfo = $message->getFileName() ? sprintf(' [Fichier: %s]', $message->getFileName()) : '';

        return sprintf(
            '[Réf: #%s?jumpTo=%d | %s] %s: %s%s',
            $channelSlug,
            $messageId,
            $date,
            $author,
            $content,
            $fileInfo,
        );
    }

    /**
     * Formats a message into a structured associative array for JSON summary batches.
     *
     * @return array{date: string, auteur: string, contenu: string}
     */
    public function formatStructured(Message $message): array
    {
        return [
            'date' => $message->getCreatedAt()->format('Y-m-d H:i'),
            'auteur' => $message->getAuthor()?->getUsername() ?? 'Robot',
            'contenu' => $message->isPoll()
                ? '[Sondage] ' . ($message->getPoll()?->getQuestion() ?? '')
                : $message->getContent() ?? '',
        ];
    }
}
