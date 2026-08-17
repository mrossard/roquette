<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Channel;
use App\Entity\Workspace;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class HelpPromptBuilder
{
    private MessagePromptFormatter $messagePromptFormatter;

    public function __construct(
        private DocumentContextBuilder $documentContextBuilder,
        private ChannelRepository $channelRepository,
        private MessageRepository $messageRepository,
        #[Autowire(env: 'int:LLM_MEMORY_MESSAGES')]
        private int $memoryMessages = 10,
        ?MessagePromptFormatter $messagePromptFormatter = null,
    ) {
        $this->messagePromptFormatter = $messagePromptFormatter ?? new MessagePromptFormatter();
    }

    /**
     * Builds the default prompt and system prompt with workspace, channels, and documentation context.
     *
     * @param list<Channel> $channels
     * @return array{0: string, 1: string}
     */
    public function buildDefaultPrompts(
        string $question,
        array $channels,
        ?Workspace $currentWorkspace = null,
        ?Channel $currentChannel = null,
    ): array {
        $context = $this->documentContextBuilder->buildContext($question);

        $channelList = array_map(static fn($c) => sprintf(
            '- Nom: "%s", Slug: "%s", Workspace: "%s"',
            $c->getName(),
            $c->getSlug(),
            $c->getWorkspace()?->getName() ?? 'Hors workspace',
        ), $channels);

        $currentWorkspaceName = $currentWorkspace?->getName();
        $currentWorkspaceHint = $currentWorkspaceName !== null
            ? "Tu es actuellement dans le workspace \"{$currentWorkspaceName}\". "
            : '';

        $currentChannelHint = $currentChannel !== null
            ? "CANAL ACTUEL : L'utilisateur est actuellement dans le canal \"{$currentChannel->getName()}\" (Slug: \"{$currentChannel->getSlug()}\"). Quand il fait référence à \"ce canal\", \"ici\" ou ne précise pas de canal, cible le canal \"{$currentChannel->getSlug()}\".\n"
            : '';

        $now = new \DateTimeImmutable();

        $systemPrompt =
            "Tu es 'Assistant Roquette', un assistant virtuel d'aide dédié EXCLUSIVEMENT à l'application Roquette.\n"
            . "La date et l'heure actuelles sont : "
            . $now->format('d/m/Y H:i')
            . ".\n"
            . $currentChannelHint
            . "CONSIGNES STRICTES DE PÉRIMÈTRE ET DE SÉCURITÉ :\n"
            . "- Tu réponds UNIQUEMENT et EXCLUSIVEMENT aux questions à l'aide des outils (Tools) disponibles ou de la documentation utilisateur fournie ci-dessous.\n"
            . "- Si la demande concerne une action ou une donnée de l'application (créer un sondage, programmer un rappel, résumer un canal, chercher un message/fichier), tu DOIS appeler l'outil (Tool) correspondant.\n"
            . "- Si la question concerne l'utilisation ou les fonctionnalités de Roquette, réponds en te basant STRICTEMENT sur la documentation utilisateur ci-dessous.\n"
            . "- Si une demande ne peut pas être traitée par l'un des outils disponibles NI par la documentation utilisateur (ex: questions de culture générale, code hors sujet, demandes sans rapport avec Roquette), tu DOIS poliment refuser d'y répondre en précisant que tu es uniquement formé pour aider sur l'application Roquette et exécuter ses fonctionnalités.\n\n"
            . "Tu peux créer des sondages interactifs dans un canal en appelant l'outil 'create_poll'.\n"
            . "Tu peux programmer des rappels en appelant l'outil 'schedule_reminder'.\n"
            . "Tu peux résumer les échanges d'un canal en appelant l'outil 'summarize_channel'.\n"
            . "Tu peux rechercher des messages/fichiers en appelant l'outil 'search_messages'.\n"
            . "Liste des canaux existants :\n"
            . implode("\n", $channelList)
            . "\n\n"
            . $currentWorkspaceHint
            . "DIRECTIVES STRICTES SUR LES OUTILS :\n"
            . "- Pour utiliser un outil ('schedule_reminder', 'create_poll', 'summarize_channel', 'search_messages'), tu DOIS EXCLUSIVEMENT effectuer un appel d'outil natif (tool call / function call).\n"
            . "- Ne génère JAMAIS de JSON brut, d'objet JSON, ni de bloc de code (ex: ```json ... ``` ou {\"tool\": ...}) dans ton message texte pour appeler un outil.\n"
            . "- N'écris AUCUN texte d'accompagnement ni format JSON dans ta réponse lorsque tu déclenches un outil.\n\n"
            . "POUR TOUS LES OUTILS, le paramètre 'channelSlug' doit TOUJOURS être le Slug exact d'un canal de la liste ci-dessus. "
            . "Quand l'utilisateur cite un canal par son NOM sans préciser de workspace, résous-le dans le workspace courant.\n\n"
            . "RÈGLES IMPÉRATIVES POUR 'create_poll' :\n"
            . "- Ne mets JAMAIS les choix de réponse dans le champ 'question'. La 'question' doit être uniquement l'intitulé (ex: 'Quel est votre choix ?').\n"
            . "- Extraie TOUJOURS chaque alternative ou choix de réponse sous forme d'éléments séparés dans le tableau 'options' (ex: pour 'A, B ou C? Voire D?', extrais options: ['A', 'B', 'C', 'D']).\n"
            . "- Si l'utilisateur mentionne 'plusieurs choix', 'choix multiples', 'plusieurs réponses' ou équivalent, passe impérativement 'allowMultiple' à true (sinon false).\n"
            . "- Ne laisse JAMAIS le tableau 'options' vide ou avec une seule option.\n\n"
            . "RÈGLES IMPÉRATIVES POUR 'schedule_reminder' :\n"
            . "- Dans 'reminderText', extrais EXCLUSIVEMENT l'action ou le sujet brut de la tâche (ex: pour 'Rappelle-moi de finir mon verre dans 2 minutes', extrais 'Finir mon verre').\n"
            . "- Ne mets JAMAIS les mots 'Rappel', 'Rappelle-moi', ni le délai/durée (ex: 'dans 2 minutes') dans le champ 'reminderText'.\n"
            . "- Calcule 'delayMinutes' uniquement à partir de l'heure courante fournie ci-dessus : si l'utilisateur donne une heure absolue (ex: 'à 11h10'), fais la différence entre cette heure et l'heure actuelle ; s'il donne un délai (ex: 'dans 2 minutes'), utilise ce délai tel quel.\n"
            . "- Pour un rappel personnel, utilise 'channelSlug': 'assistant'.\n\n"
            . "Documentation utilisateur :\n"
            . $context;

        return [$question, $systemPrompt];
    }

    /**
     * Appends previous message history from the channel into the prompt.
     */
    public function addConversationContext(string $prompt, string $channelSlug, string $question): string
    {
        if ($this->memoryMessages <= 0) {
            return $prompt;
        }

        $channel = $this->channelRepository->findOneBy(['slug' => $channelSlug]);
        if (!$channel) {
            return $prompt;
        }

        $recent = $this->messageRepository->findLatestInChannel($channel, $this->memoryMessages + 1);
        if ([] === $recent) {
            return $prompt;
        }

        // findLatestInChannel returns messages DESC: reverse to chronological and skip the last
        // one (the message that triggered this very request).
        $recent = array_reverse($recent);
        $history = [];
        foreach ($recent as $msg) {
            $content = trim($msg->getContent() ?? '');
            if ($content === '' || $msg->isPoll() || $content === trim($question)) {
                continue;
            }

            // Exclude obsolete confirmation prompts from history
            if (
                str_contains($content, 'bouton de confirmation')
                || (str_contains($content, 'confirmer') && str_contains($content, 'répondant ok'))
            ) {
                continue;
            }

            $history[] = $this->messagePromptFormatter->formatLine($msg, 500);
        }

        if ([] === $history) {
            return $prompt;
        }

        return (
            "Historique de la conversation (messages précédents) :\n"
            . implode("\n", $history)
            . "\n\n---\n\n"
            . $prompt
        );
    }
}
