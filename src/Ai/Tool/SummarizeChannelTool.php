<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Ai\ChannelResolver;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;

final readonly class SummarizeChannelTool implements AiToolInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private MessageRepository $messageRepository,
        private ChannelResolver $channelResolver,
    ) {}

    public function getName(): string
    {
        return 'summarize_channel';
    }

    public function getDescription(): string
    {
        return "Permet à l'assistant de lire et résumer les messages récents d'un canal spécifique auquel l'utilisateur a accès.";
    }

    /**
     * @return array<string, mixed>
     */
    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'channelSlug' => [
                    'type' => 'string',
                    'description' => "Le nom ou le slug du canal à résumer (ex: 'general', 'dev').",
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => "Le nombre maximum de récents messages à inclure dans l'analyse (par défaut 50).",
                ],
            ],
            'required' => ['channelSlug'],
        ];
    }

    /**
     * @param string $channelSlug Nom ou slug du canal à résumer.
     * @param int $limit Nombre max de messages (par défaut 50).
     * @param int|null $authorUserId ID de l'utilisateur.
     * @param int|null $workspaceId ID du workspace.
     */
    public function __invoke(
        string $channelSlug,
        int $limit = 50,
        ?int $authorUserId = null,
        ?int $workspaceId = null,
    ): string {
        $userId = $authorUserId ?? 0;
        $result = $this->execute(['channelSlug' => $channelSlug, 'limit' => $limit], $userId, $workspaceId);

        if (isset($result['error'])) {
            return (string) $result['error'];
        }

        if (isset($result['result'])) {
            return (string) $result['result'];
        }

        return sprintf(
            "Résumé des messages récents du canal #%s :\n\n%s",
            $result['channelName'] ?? $channelSlug,
            $result['messages'] ?? ''
        );
    }
    public function execute(array $arguments, int $userId, ?int $workspaceId = null): array
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return ['error' => 'Utilisateur non trouvé.'];
        }

        $channelSlug = trim((string) ($arguments['channelSlug'] ?? ''));
        if ($channelSlug === '') {
            return ['error' => 'Veuillez fournir un canal valide.'];
        }

        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 50;
        $limit = max(5, min(100, $limit));

        $channel = $this->channelResolver->resolve($channelSlug, $workspaceId);
        if (!$channel) {
            return ['error' => sprintf("Canal '%s' non trouvé ou vous n'y avez pas accès.", $channelSlug)];
        }

        $recentMessages = $this->messageRepository->findLatestInChannel($channel, $limit);
        if ([] === $recentMessages) {
            return ['result' => sprintf("Le canal #%s ne contient aucun message récent à résumer.", $channel->getName())];
        }

        $recentMessages = array_reverse($recentMessages);
        $extractedText = [];

        foreach ($recentMessages as $msg) {
            $content = trim($msg->getContent() ?? '');
            if ($content === '' || $msg->isPoll()) {
                continue;
            }

            $author = $msg->getAuthor()?->getDisplayName() ?: ($msg->getAuthor()?->getUsername() ?? 'Inconnu');
            $date = $msg->getCreatedAt()->format('d/m H:i');
            $extractedText[] = sprintf('[%s] %s: %s', $date, $author, mb_substr($content, 0, 500));
        }

        if ([] === $extractedText) {
            return ['result' => sprintf("Le canal #%s ne contient pas de texte exploitable à résumer.", $channel->getName())];
        }

        return [
            'channelName' => $channel->getName(),
            'messageCount' => count($extractedText),
            'messages' => implode("\n", $extractedText),
            'instruction' => 'Voici les messages extraits du canal. Rédige un résumé clair, concis et structuré pour l\'utilisateur.',
        ];
    }
}
