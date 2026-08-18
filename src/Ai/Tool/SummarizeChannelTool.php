<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Ai\ChannelResolver;
use App\Ai\MessagePromptFormatter;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\ChannelAccessService;

final readonly class SummarizeChannelTool extends AbstractAiTool
{
    private MessagePromptFormatter $messagePromptFormatter;

    public const string NAME = 'summarize_channel';

    public function __construct(
        private UserRepository $userRepository,
        private MessageRepository $messageRepository,
        private ChannelResolver $channelResolver,
        private ChannelAccessService $channelAccessService,
        ?MessagePromptFormatter $messagePromptFormatter = null,
    ) {
        $this->messagePromptFormatter = $messagePromptFormatter ?? new MessagePromptFormatter();
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return "Permet à l'assistant de lire et résumer les messages récents d'un canal spécifique auquel l'utilisateur a accès.";
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

        if (($result['error'] ?? null) !== null) {
            return (string) $result['error'];
        }

        if (($result['result'] ?? null) !== null) {
            return (string) $result['result'];
        }

        return sprintf(
            "Résumé des messages récents du canal #%s :\n\n%s",
            $result['channelName'] ?? $channelSlug,
            $result['messages'] ?? '',
        );
    }

    public function execute(array $arguments, int $userId, ?int $workspaceId = null): array
    {
        $user = $this->resolveUser($this->userRepository, $userId);
        if (!$user) {
            return $this->formatError('Utilisateur non trouvé.');
        }

        $channelSlug = trim((string) ($arguments['channelSlug'] ?? ''));
        if ($channelSlug === '') {
            return $this->formatError('Veuillez fournir un canal valide.');
        }

        $limit = \array_key_exists('limit', $arguments) && $arguments['limit'] !== null
            ? (int) $arguments['limit']
            : 50;
        $limit = max(5, min(100, $limit));

        $resolved = $this->resolveChannelAndCheckAccess(
            $this->channelResolver,
            $this->channelAccessService,
            $channelSlug,
            $user,
            $workspaceId,
        );
        if ($resolved['error'] !== null) {
            return $this->formatError($resolved['error']);
        }

        $channel = $resolved['channel'];
        if ($channel === null) {
            return $this->formatError(sprintf("Canal '%s' non trouvé ou vous n'y avez pas accès.", $channelSlug));
        }

        $recentMessages = $this->messageRepository->findLatestInChannel($channel, $limit);
        if ([] === $recentMessages) {
            return $this->formatSuccess(sprintf(
                'Le canal #%s ne contient aucun message récent à résumer.',
                $channel->getName(),
            ));
        }

        $recentMessages = array_reverse($recentMessages);
        $extractedText = [];

        foreach ($recentMessages as $msg) {
            $content = trim($msg->getContent() ?? '');
            if ($content === '' || $msg->isPoll()) {
                continue;
            }

            $extractedText[] = $this->messagePromptFormatter->formatLineWithDate($msg, 500, 'd/m H:i');
        }

        if ([] === $extractedText) {
            return $this->formatSuccess(sprintf(
                'Le canal #%s ne contient pas de texte exploitable à résumer.',
                $channel->getName(),
            ));
        }

        return [
            'channelName' => $channel->getName(),
            'messageCount' => count($extractedText),
            'messages' => implode("\n", $extractedText),
            'instruction' => 'Voici les messages extraits du canal. Rédige un résumé clair, concis et structuré pour l\'utilisateur.',
        ];
    }
}
