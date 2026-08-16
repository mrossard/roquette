<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\User;
use App\Service\MessageFormatter;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

final readonly class HelpStreamPublisher
{
    public function __construct(
        private HubInterface $hub,
        private MessageFormatter $messageFormatter,
        private Environment $twig,
        private string $mercureTopicPrefix,
    ) {}

    public function getPersonalTopic(User $user): string
    {
        return $this->mercureTopicPrefix . '/users/' . $user->getUsername();
    }

    /**
     * Publishes a formatted status/progress markdown message.
     */
    public function publishStatus(
        string $topic,
        string $helpMessageId,
        string $statusMarkdown,
        string $channelSlug,
    ): void {
        $html = $this->messageFormatter->format($statusMarkdown);
        $this->publishHtml($topic, $helpMessageId, $html, $channelSlug);
    }

    /**
     * Publishes a streaming chunk with optional tool confirmation button.
     */
    public function publishStreamText(
        string $topic,
        string $helpMessageId,
        string $prefix,
        string $accumulatedText,
        string $channelSlug,
        #[\SensitiveParameter]
        ?string $confirmationToken = null,
    ): void {
        $html = $this->messageFormatter->format($prefix . $accumulatedText);

        if ($confirmationToken !== null) {
            $html .= $this->twig->render('dashboard/_tool_confirmation.html.twig', [
                'token' => $confirmationToken,
            ]);
        }

        $this->publishHtml($topic, $helpMessageId, $html, $channelSlug);
    }

    /**
     * Publishes a standard error message when LLM processing fails.
     */
    public function publishError(string $topic, string $helpMessageId, string $channelSlug): void
    {
        $errorHtml =
            '<p style="color: var(--accent-red, #ff5b5b);">Désolé, une erreur est survenue lors de la communication avec le robot d\'aide. '
            . 'Veuillez réessayer dans un instant.</p>';

        $this->publishHtml($topic, $helpMessageId, $errorHtml, $channelSlug);
    }

    /**
     * Publishes raw HTML to the Mercure topic.
     */
    public function publishHtml(string $topic, string $helpMessageId, string $html, string $channelSlug): void
    {
        $renderedHtml = $this->twig->render('dashboard/_help_message_update.html.twig', [
            'helpMessageId' => $helpMessageId,
            'html' => $html,
            'timestamp' => new \DateTime(),
            'channelSlug' => $channelSlug,
        ]);

        $update = new Update($topic, $renderedHtml, true, null, 'help_stream_update');

        $this->hub->publish($update);
    }
}
