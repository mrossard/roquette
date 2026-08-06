<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Repository\MessageRepository;
use App\Repository\UserRepository;

final readonly class SearchMessagesTool implements AiToolInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private MessageRepository $messageRepository,
    ) {}

    public function getName(): string
    {
        return 'search_messages';
    }

    public function getDescription(): string
    {
        return "Recherche des messages ou des fichiers partagés dans tous les canaux accessibles par l'utilisateur.";
    }

    /**
     * @return array<string, mixed>
     */
    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => "Terme ou texte à chercher dans les messages (ex: 'déploiement', 'lien doc').",
                ],
                'author' => [
                    'type' => 'string',
                    'description' => "Nom d'utilisateur ou nom d'affichage de l'auteur du message (optionnel).",
                ],
                'channel' => [
                    'type' => 'string',
                    'description' => 'Nom du canal où restreindre la recherche (optionnel).',
                ],
                'hasFile' => [
                    'type' => 'boolean',
                    'description' => 'Si true, ne cherche que les messages contenant des fichiers joints.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    /**
     * @param string $query Terme recherché.
     * @param string|null $author Auteur filtré.
     * @param string|null $channel Canal filtré.
     * @param bool|null $hasFile Filtre pièces jointes.
     * @param int|null $authorUserId ID de l'utilisateur.
     * @param int|null $workspaceId ID du workspace.
     */
    public function __invoke(
        string $query,
        ?string $author = null,
        ?string $channel = null,
        ?bool $hasFile = null,
        ?int $authorUserId = null,
        ?int $workspaceId = null,
    ): string {
        $userId = $authorUserId ?? 0;
        $args = [
            'query' => $query,
            'author' => $author,
            'channel' => $channel,
            'hasFile' => $hasFile,
        ];
        $result = $this->execute($args, $userId, $workspaceId);

        if (isset($result['error'])) {
            return (string) $result['error'];
        }

        if (isset($result['result'])) {
            return (string) $result['result'];
        }

        return sprintf(
            "Résultats de recherche (%d trouvés) :\n\n%s",
            $result['count'] ?? 0,
            $result['results'] ?? ''
        );
    }
    public function execute(array $arguments, int $userId, ?int $workspaceId = null): array
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return ['error' => 'Utilisateur non trouvé.'];
        }

        $query = trim((string) ($arguments['query'] ?? ''));
        $author = isset($arguments['author']) && trim((string) $arguments['author']) !== '' ? trim((string) $arguments['author']) : null;
        $channel = isset($arguments['channel']) && trim((string) $arguments['channel']) !== '' ? trim((string) $arguments['channel']) : null;
        $hasFile = isset($arguments['hasFile']) ? (bool) $arguments['hasFile'] : null;

        $results = $this->messageRepository->searchGlobal(
            currentUser: $user,
            authorUsername: $author,
            channelName: $channel,
            hasFile: $hasFile,
            textQuery: $query !== '' ? $query : null,
        );

        if ([] === $results) {
            return ['result' => "Aucun message correspondant à votre recherche n'a été trouvé."];
        }

        $results = array_slice($results, 0, 15);
        $formatted = [];

        foreach ($results as $msg) {
            $authorName = $msg->getAuthor()?->getDisplayName() ?: ($msg->getAuthor()?->getUsername() ?? 'Inconnu');
            $channelName = $msg->getChannel()?->getName() ?? 'Inconnu';
            $channelSlug = $msg->getChannel()?->getSlug() ?? 'general';
            $date = $msg->getCreatedAt()->format('d/m/Y H:i');
            $content = mb_substr(trim($msg->getContent() ?? ''), 0, 300);
            $messageId = $msg->getId();

            $fileInfo = '';
            if ($msg->getFileName()) {
                $fileInfo = sprintf(' [Fichier: %s]', $msg->getFileName());
            }

            // Ex: #general?jumpTo=123 ou #general
            $formatted[] = sprintf('[Réf: #%s?jumpTo=%d | %s] %s: %s%s', $channelSlug, $messageId, $date, $authorName, $content, $fileInfo);
        }

        return [
            'count' => count($formatted),
            'results' => implode("\n", $formatted),
            'instruction' => "Synthétise ces résultats de recherche pour répondre précisément à l'utilisateur. Pour chaque message trouvé, cite systématiquement le canal sous la forme '#slug-du-canal' (par exemple '#general' ou '#dev'), ce qui sera automatiquement converti en lien cliquable par l'application.",
        ];
    }
}
