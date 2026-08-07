<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Channel;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\MessageRepository;

final readonly class AiContextBuilder
{
    public function __construct(
        private MessageRepository $messageRepository,
        private DocumentContextBuilder $documentContextBuilder,
        private int $memoryMessages = 10,
    ) {}

    /**
     * Builds standard prompts and system instructions for general assistant queries.
     *
     * @param list<Channel> $channels
     * @return array{0: string, 1: string}
     */
    public function buildDefaultHelpPrompts(
        string $question,
        array $channels,
        ?Workspace $workspace = null,
        ?Channel $currentChannel = null,
        ?User $user = null,
    ): array {
        $workspaceInfo = '';
        if ($workspace !== null) {
            $workspaceInfo = sprintf("Espace de travail courant : '%s' (ID: %d)\n", $workspace->getName(), $workspace->getId());
        }

        $accessibleChannelsInfo = "Canaux accessibles par l'utilisateur :\n";
        foreach ($channels as $ch) {
            $typeStr = $ch->isPrivate() ? 'privé' : 'public';
            $wsStr = $ch->getWorkspace() ? ' | Workspace: ' . $ch->getWorkspace()->getName() : '';
            $accessibleChannelsInfo .= sprintf("- #%s (%s, %s%s)\n", $ch->getSlug(), $ch->getName(), $typeStr, $wsStr);
        }

        $channelContext = '';
        if ($currentChannel !== null && !$currentChannel->isDm()) {
            $latest = array_reverse($this->messageRepository->findLatestInChannel($currentChannel, $this->memoryMessages));
            if ($latest !== []) {
                $channelContext = sprintf("\n\nDerniers messages échangés dans le canal #%s :\n", $currentChannel->getSlug());
                foreach ($latest as $msg) {
                    $authorName = $msg->getAuthor()?->getDisplayName() ?? $msg->getAuthor()?->getUsername() ?? 'Inconnu';
                    $text = trim($msg->getContent() ?? '');
                    if ($text !== '') {
                        $channelContext .= sprintf("[%s] %s: %s\n", $msg->getCreatedAt()->format('H:i'), $authorName, mb_substr($text, 0, 300));
                    }
                }
            }
        }

        $docContext = $this->documentContextBuilder->buildContext($question, 5);

        $systemPrompt = <<<SYS
Vous êtes l'assistant virtuel intelligent de l'application de messagerie Roquette.
Votre rôle est d'aider les utilisateurs avec courtoisie, clarté et précision.

=== CONTEXTE APPLICATIF ===
{$workspaceInfo}{$accessibleChannelsInfo}
=== DOCUMENTATION UTILE ===
{$docContext}

Directives :
1. Répondez en français de manière claire et bien structurée (format Markdown).
2. Pour faire référence à un canal, utilisez le format '#slug-du-canal' (ex: '#general' ou '#dev') afin qu'il soit converti en lien cliquable.
SYS;

        $fullPrompt = "Question / Demande de l'utilisateur :\n" . $question . $channelContext;

        return [$fullPrompt, $systemPrompt];
    }
}
