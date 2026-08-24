<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Ai\MessagePromptFormatter;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\HybridSearchService;

final readonly class SearchMessagesTool implements AiToolInterface
{
    private MessagePromptFormatter $messagePromptFormatter;

    public const string NAME = 'search_messages';

    public function __construct(
        private UserRepository $userRepository,
        private MessageRepository $messageRepository,
        ?MessagePromptFormatter $messagePromptFormatter = null,
        private ?HybridSearchService $hybridSearchService = null,
    ) {
        $this->messagePromptFormatter = $messagePromptFormatter ?? new MessagePromptFormatter();
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return "Recherche des messages ou des fichiers partagés (y compris le contenu interne des documents) dans tous les canaux accessibles par l'utilisateur.";
    }

    public function requiresConfirmation(): bool
    {
        return false;
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
                    'description' => "Terme ou texte à chercher dans les messages et documents joints (ex: 'déploiement', 'devis', 'architecture').",
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

        if (($result['error'] ?? null) !== null) {
            return (string) $result['error'];
        }

        if (($result['result'] ?? null) !== null) {
            return (string) $result['result'];
        }

        return sprintf("Résultats de recherche (%d trouvés) :\n\n%s", $result['count'] ?? 0, $result['results'] ?? '');
    }

    public function execute(array $arguments, int $userId, ?int $workspaceId = null): array
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return ['error' => 'Utilisateur non trouvé.'];
        }

        $query = trim((string) ($arguments['query'] ?? ''));
        $rawAuthor = \array_key_exists('author', $arguments) && $arguments['author'] !== null
            ? trim((string) $arguments['author'])
            : '';
        $author = $rawAuthor !== '' ? $rawAuthor : null;
        $channel = $this->resolveChannelFilter($arguments);
        $hasFile = array_key_exists('hasFile', $arguments) && $arguments['hasFile'] !== null
            ? (bool) $arguments['hasFile']
            : null;

        $results = $this->performGlobalSearch($user, $author, $channel, $hasFile, $query);

        if ([] === $results) {
            return ['result' => "Aucun message correspondant à votre recherche n'a été trouvé."];
        }

        $results = array_slice($results, 0, 15);
        $formatted = [];

        foreach ($results as $msg) {
            $docExcerpt = null;
            if ($msg->getFileName() !== null && $this->hybridSearchService !== null && $query !== '') {
                $docExcerpt = $this->hybridSearchService->getMatchingDocumentExcerpt((int) $msg->getId(), $query, 800);
            }

            $formatted[] = $this->messagePromptFormatter->formatSearchReference($msg, 300, $docExcerpt);
        }

        return [
            'count' => count($formatted),
            'results' => implode("\n", $formatted),
            'instruction' => "Synthétise ces résultats de recherche pour répondre précisément à l'utilisateur. Pour chaque message trouvé, cite systématiquement le canal sous la forme '#slug-du-canal' (par exemple '#general' ou '#dev'), ce qui sera automatiquement converti en lien cliquable par l'application.",
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function resolveChannelFilter(array $arguments): ?string
    {
        $raw = (string) ($arguments['channel'] ?? $arguments['channelSlug'] ?? '');
        $trimmed = trim($raw);
        if ($trimmed === '' || str_starts_with($trimmed, 'dm-robot-roquette-') || $trimmed === 'assistant') {
            return null;
        }

        return $trimmed;
    }

    /**
     * @return \App\Entity\Message[]
     */
    private function performGlobalSearch(
        \App\Entity\User $user,
        ?string $author,
        ?string $channel,
        ?bool $hasFile,
        string $query,
    ): array {
        $textQuery = $query !== '' ? $query : null;

        if ($this->hybridSearchService !== null) {
            return $this->hybridSearchService->searchGlobal(
                currentUser: $user,
                authorUsername: $author,
                channelName: $channel,
                hasFile: $hasFile,
                textQuery: $textQuery,
                limit: 15,
            );
        }

        return $this->messageRepository->searchGlobal(
            currentUser: $user,
            authorUsername: $author,
            channelName: $channel,
            hasFile: $hasFile,
            textQuery: $textQuery,
            limit: 15,
        );
    }
}
