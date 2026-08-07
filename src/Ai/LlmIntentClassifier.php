<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Channel;
use App\Entity\Workspace;
use App\Service\LlmService;
use Psr\Log\LoggerInterface;

/**
 * Classifies a user request into an assistant intent (help / resumer / sondage)
 * through an LLM JSON classification with strict validation.
 *
 * Used as the fallback of IntentClassifier when its keyword fast-path cannot
 * decide on an unambiguous intent.
 */
final readonly class LlmIntentClassifier
{
    private const array VALID_INTENTS = ['help', 'resumer', 'sondage'];

    public function __construct(
        private LlmService $llmService,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<Channel> $channels
     * @return array{intent: string, channelSlug: string|null}|null
     */
    public function classify(string $question, array $channels, string $currentChannelSlug, ?Workspace $currentWorkspace = null): ?array
    {
        $classificationPrompt = json_encode([
            'message' => $question,
            'currentWorkspace' => $currentWorkspace?->getName(),
            'channels' => $this->buildAccessibleChannels($channels, $currentChannelSlug),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        try {
            $output = $this->llmService->generateText($classificationPrompt, $this->classificationSystemPrompt());

            return $this->parseClassification($output);
        } catch (\Exception $e) {
            $this->logger->error('Classification failed:', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param list<Channel> $channels
     * @return list<array{name: string|null, slug: string|null, description: string|null, workspace: string}>
     */
    private function buildAccessibleChannels(array $channels, string $currentChannelSlug): array
    {
        $accessibleChannels = [];
        foreach ($channels as $channel) {
            if ($channel->getSlug() === $currentChannelSlug) {
                continue;
            }

            $accessibleChannels[] = [
                'name' => $channel->getName(),
                'slug' => $channel->getSlug(),
                'description' => $channel->getDescription(),
                'workspace' => $channel->getWorkspace()?->getName() ?? 'Hors workspace',
            ];
        }

        return $accessibleChannels;
    }

    private function classificationSystemPrompt(): string
    {
        return
            "Tu es un outil d'analyse d'intention d'utilisateur pour l'application Roquette. "
            . "L'entrée qui te sera fournie sous forme de prompt est un objet JSON contenant :\n"
            . "- \"message\" : Le message ou la question écrite par l'utilisateur.\n"
            . "- \"currentWorkspace\" : Le nom du workspace courant de l'utilisateur (ou null).\n"
            . "- \"channels\" : La liste des canaux auxquels l'utilisateur a accès, chaque canal ayant un \"name\", \"slug\", \"description\", et \"workspace\".\n\n"
            . "Ton rôle unique est de classifier le message pour déterminer l'intention de l'utilisateur et d'extraire le slug du canal cible si nécessaire. "
            . "Quand l'utilisateur cite un canal par son NOM, privilégie en priorité les canaux du \"currentWorkspace\".\n\n"
            . "Les intentions possibles sont :\n"
            . "1. \"resumer\" : L'utilisateur demande explicitement un résumé des messages récents d'un canal (ex. : 'résume le canal général', 'fais-moi une synthèse de htmx').\n"
            . "2. \"sondage\" : L'utilisateur demande de créer ou lancer un sondage dans un canal (ex. : 'crée un sondage', 'lance un vote').\n"
            . "3. \"help\" : L'utilisateur pose une question générale, demande de l'aide ou une action interactive.\n\n"
            . "Tu dois répondre STRICTEMENT sous format JSON avec la structure suivante, sans aucun autre texte (pas de markdown, pas de blocs de code) :\n"
            . "{\n"
            . "  \"intent\": \"resumer\", \"sondage\", ou \"help\",\n"
            . "  \"channelSlug\": \"le slug du canal cible\" (ou null)\n"
            . '}';
    }

    private function parseClassification(string $output): ?array
    {
        $jsonText = trim($output);
        if (str_starts_with($jsonText, '```')) {
            $jsonText = trim((string) preg_replace('/^```(?:json)?|```$/', '', $jsonText));
        }

        $data = json_decode($jsonText, true);
        if (!\is_array($data)) {
            $this->logger->warning('Intent classification returned no JSON', ['raw' => $jsonText]);

            return null;
        }

        $intent = $data['intent'] ?? null;
        if (!\is_string($intent) || !\in_array($intent, self::VALID_INTENTS, true)) {
            $this->logger->warning('Intent classification returned invalid intent', ['intent' => $intent]);

            return null;
        }

        $channelSlug = $data['channelSlug'] ?? null;
        if (!\is_string($channelSlug) || $channelSlug === '') {
            $channelSlug = null;
        }

        return ['intent' => $intent, 'channelSlug' => $channelSlug];
    }
}
