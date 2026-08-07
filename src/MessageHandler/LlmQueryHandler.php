<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Ai\ChannelResolver;
use App\Ai\ChannelSummaryBuilder;
use App\Ai\DocumentContextBuilder;
use App\Ai\IntentClassifier;
use App\Ai\ToolActionSigner;
use App\Ai\ToolRegistry;
use App\Ai\ToolRunState;
use App\Ai\ToolRunner;
use App\Entity\User;
use App\Message\LlmQueryMessage;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\LlmService;
use App\Service\MessageFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class LlmQueryHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ChannelRepository $channelRepository,
        private MessageRepository $messageRepository,
        private LlmService $llmService,
        private MessageFormatter $messageFormatter,
        private HubInterface $hub,
        private EntityManagerInterface $entityManager,
        private string $mercureTopicPrefix,
        private LoggerInterface $logger,
        private \Twig\Environment $twig,
        private ToolRegistry $toolRegistry,
        private ToolRunner $toolRunner,
        private \App\Repository\WorkspaceRepository $workspaceRepository,
        private ChannelResolver $channelResolver,
        private IntentClassifier $intentClassifier,
        private ChannelSummaryBuilder $summaryBuilder,
        private DocumentContextBuilder $documentContextBuilder,
        private ToolActionSigner $toolActionSigner,
        #[Autowire(env: 'bool:LLM_TOOLS_ENABLED')]
        private bool $toolsEnabled = true,
        #[Autowire(env: 'int:LLM_MEMORY_MESSAGES')]
        private int $memoryMessages = 10,
    ) {}

    public function __invoke(LlmQueryMessage $message): void
    {
        $user = $this->userRepository->find($message->getUserId());
        if (!$user) {
            return;
        }

        $personalTopic = $this->mercureTopicPrefix . '/users/' . $user->getUsername();
        $channelSlug = $message->getChannelSlug();
        $startedAt = microtime(true);
        $state = new ToolRunState();

        // 1. Immediately upon receipt: show "Analyse de la demande... 🔍"
        $initialHtml = $this->messageFormatter->format('Analyse de la demande... 🔍');
        $this->publishUpdate($personalTopic, $message->getHelpMessageId(), $initialHtml, $channelSlug);

        $channels = $this->channelRepository->findAllForUser($user);

        $workspace = null;
        if ($message->getWorkspaceId() !== null) {
            $workspace = $this->workspaceRepository->find($message->getWorkspaceId());
        }

        $currentChannel = $this->channelResolver->resolveFromList($channelSlug, $channels)
            ?? $this->channelRepository->findOneBy(['slug' => $channelSlug]);

        [$prompt, $systemPrompt] = $this->getDefaultHelpPrompts($message->getQuestion(), $channels, $workspace, $currentChannel);

        $intent = $message->getIntent() ?? 'help';
        $channelName = null;
        $batches = null;
        $targetChannelSlug = null;

        try {
            if ($message->getIntent() === null && str_starts_with($channelSlug, 'dm-robot-roquette-')) {
                $classification = $this->intentClassifier->classify($message->getQuestion(), $channels, $channelSlug, $workspace);
                $this->logger->info('Classification result:', ['classification' => $classification]);
                $intent = $classification['intent'];
                $targetChannelSlug = $classification['channelSlug'];
            } else {
                $targetChannelSlug = $channelSlug;
            }

            if ($intent === 'resumer' && $targetChannelSlug) {
                $targetChannel = $this->channelResolver->resolveFromList($targetChannelSlug, $channels);
                $channelName = $targetChannel ? $targetChannel->getName() : $targetChannelSlug;
                [$prompt, $systemPrompt, $batches] = $this->summaryBuilder->build($user, $channels, $targetChannelSlug);
            }

            // 2. Once classification is done: reformulate the request based on intent
            if ($intent === 'resumer') {
                $reformulation = 'Résumé du canal **#' . ($channelName ?? 'inconnu') . '**... ⏳';
                $prefix = '**Résumé du canal #' . ($channelName ?? 'inconnu') . "** :\n\n";
            } elseif ($intent === 'sondage') {
                $reformulation = 'Création du sondage... ⏳';
                $prefix = '';
            } else {
                $reformulation = 'Traitement de la demande... ⏳';
                $prefix = '';
            }

            $this->publishUpdate(
                $personalTopic,
                $message->getHelpMessageId(),
                $this->messageFormatter->format($reformulation),
                $channelSlug,
            );

            if ($batches !== null && count($batches) > 1) {
                [$prompt, $systemPrompt] = $this->summarizeBatches(
                    $batches,
                    $prompt,
                    $systemPrompt,
                    $personalTopic,
                    $message->getHelpMessageId(),
                    $channelSlug,
                );
            }

            if ($intent === 'help') {
                $prompt = $this->addConversationContext($prompt, $channelSlug, $message->getQuestion());
            }

            $accumulatedText = '';
            $generator = $this->createGenerator($prompt, $systemPrompt, $message, $personalTopic, $state);

            $chunkCount = 0;
            foreach ($generator as $chunk) {
                $accumulatedText .= $chunk;
                $chunkCount++;

                if ($chunkCount <= 3 || ($chunkCount % 3) === 0) {
                    $formattedHtml = $this->messageFormatter->format($prefix . $accumulatedText);
                    $this->publishUpdate($personalTopic, $message->getHelpMessageId(), $formattedHtml, $channelSlug);
                }
            }

            $formattedHtml = $this->messageFormatter->format($prefix . $accumulatedText);

            if ($state->pendingConfirmation !== null) {
                $formattedHtml .= $this->twig->render('dashboard/_tool_confirmation.html.twig', [
                    'token' => $state->pendingConfirmation,
                ]);
            }

            $this->publishUpdate($personalTopic, $message->getHelpMessageId(), $formattedHtml, $channelSlug);

            $this->logger->info('LlmQueryHandler completed', [
                'intent' => $intent,
                'channelSlug' => $channelSlug,
                'targetChannelSlug' => $targetChannelSlug,
                'batchCount' => $batches !== null ? count($batches) : 0,
                'chunkCount' => $chunkCount,
                'toolsExecuted' => $state->toolsExecuted,
                'confirmationRequested' => $state->pendingConfirmation !== null,
                'durationMs' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            // Persist the message in the database so it is saved only if it is a DM with the robot
            $robotUser = $this->userRepository->findOneBy(['username' => User::ROBOT_USERNAME]);
            $channel = $this->channelRepository->findOneBy(['slug' => $message->getChannelSlug()]);
            if ($robotUser && $channel && str_starts_with($channel->getSlug(), 'dm-robot-roquette-')) {
                $dbMessage = new \App\Entity\Message();
                $dbMessage->setAuthor($robotUser);
                $dbMessage->setChannel($channel);
                $dbMessage->setContent($prefix . $accumulatedText);
                $dbMessage->setCreatedAt(new \DateTimeImmutable());
                $this->entityManager->persist($dbMessage);
                $this->entityManager->flush();
            }
        } catch (\Exception $e) {
            $this->logger->error('LlmQueryHandler failed:', [
                'exception' => $e,
                'helpMessageId' => $message->getHelpMessageId(),
            ]);
            $errorHtml =
                '<p style="color: var(--accent-red, #ff5b5b);">Désolé, une erreur est survenue lors de la communication avec le robot d\'aide. '
                . 'Veuillez réessayer dans un instant.</p>';
            $this->publishUpdate($personalTopic, $message->getHelpMessageId(), $errorHtml, $channelSlug);
        }
    }

    /**
     * @param list<list<array<string, string>>> $batches
     * @return array{0: string, 1: string}
     */
    private function summarizeBatches(
        array $batches,
        string $prompt,
        string $systemPrompt,
        string $personalTopic,
        string $helpMessageId,
        string $channelSlug,
    ): array {
        $intermediateSummaries = [];
        $totalBatches = count($batches);

        $intermediateSystemPrompt =
            "Tu es 'Assistant Roquette', un assistant virtuel d'aide pour l'application Roquette.\n"
            . "Rédige une synthèse claire, structurée et concise du lot de messages fourni.\n"
            . "Consignes de traitement :\n"
            . "- Analyse les données JSON fournies pour en extraire les principaux sujets abordés, les questions résolues ou en cours, ainsi que les décisions importantes.\n"
            . "- Rédige une synthèse du lot de discussion, claire et concise.\n"
            . '- ATTENTION : Ne fais pas une retranscription brute ou une paraphrase message par message de la discussion. Ne cite pas chaque message un par un.';

        foreach ($batches as $index => $batch) {
            $batchNum = $index + 1;
            $this->publishUpdate(
                $personalTopic,
                $helpMessageId,
                $this->messageFormatter->format("Analyse et résumé du lot {$batchNum}/{$totalBatches}... ⏳"),
                $channelSlug,
            );

            $batchPrompt = json_encode($batch, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $intermediateSummaries[] = $this->llmService->generateText($batchPrompt, $intermediateSystemPrompt);
        }

        $this->publishUpdate(
            $personalTopic,
            $helpMessageId,
            $this->messageFormatter->format('Génération du résumé final combiné... ⏳'),
            $channelSlug,
        );

        $prompt = "Voici les synthèses des différents lots de la discussion à combiner :\n\n";
        foreach ($intermediateSummaries as $index => $subSummary) {
            $batchNum = $index + 1;
            $prompt .= "--- Résumé du Lot {$batchNum} ---\n{$subSummary}\n\n";
        }

        $systemPrompt =
            "Tu es 'Assistant Roquette', un assistant virtuel d'aide pour l'application Roquette.\n"
            . "Rédige une synthèse globale unique, claire, structurée et cohérente combinant les résumés des différents lots de discussion fournis ci-dessous.\n"
            . "Consignes de traitement :\n"
            . "- Fusionne les sujets redondants ou continus pour en faire une synthèse thématique unifiée.\n"
            . "- Rédige une synthèse claire et concise dans la même langue que les résumés fournis.\n"
            . '- Ne fais pas une simple juxtaposition des résumés. Fais-en une synthèse globale.';

        return [$prompt, $systemPrompt];
    }

    private function createGenerator(
        string $prompt,
        string $systemPrompt,
        LlmQueryMessage $message,
        string $personalTopic,
        ToolRunState $state,
    ): \Generator {
        if (!$this->toolsEnabled) {
            return $this->llmService->generateTextStream($prompt, $systemPrompt);
        }

        $tools = $this->toolRegistry->getOpenAiTools();
        if ([] === $tools) {
            return $this->llmService->generateTextStream($prompt, $systemPrompt);
        }

        return $this->toolRunner->streamResponse(
            $prompt,
            $systemPrompt,
            $tools,
            $message->getUserId(),
            $message->getWorkspaceId(),
            function (string $toolName) use ($personalTopic, $message, $state): void {
                $state->toolsExecuted++;
                $this->publishUpdate(
                    $personalTopic,
                    $message->getHelpMessageId(),
                    $this->messageFormatter->format(sprintf("Exécution de l'outil **%s**... ⏳", $toolName)),
                    $message->getChannelSlug(),
                );
            },
            function (string $toolName, array $arguments) use ($message, $state): void {
                $state->pendingConfirmation = $this->toolActionSigner->sign([
                    'tool' => $toolName,
                    'args' => $arguments,
                    'uid' => $message->getUserId(),
                    'ws' => $message->getWorkspaceId(),
                    'helpMessageId' => $message->getHelpMessageId(),
                    'channelSlug' => $message->getChannelSlug(),
                ]);
            },
        );
    }

    private function addConversationContext(string $prompt, string $channelSlug, string $question): string
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

            $author = $msg->getAuthor()?->getUsername() ?? 'robot-roquette';
            $history[] = sprintf('%s: %s', $author, mb_substr($content, 0, 500));
        }

        if ([] === $history) {
            return $prompt;
        }

        return "Historique de la conversation (messages précédents) :\n"
            . implode("\n", $history)
            . "\n\n---\n\n"
            . $prompt;
    }

    /**
     * @param list<\App\Entity\Channel> $channels
     * @return array{0: string, 1: string}
     */
    private function getDefaultHelpPrompts(
        string $question,
        array $channels,
        ?\App\Entity\Workspace $currentWorkspace = null,
        ?\App\Entity\Channel $currentChannel = null,
    ): array {
        $context = $this->documentContextBuilder->buildContext($question);

        $channelList = array_map(
            fn($c) => sprintf(
                '- Nom: "%s", Slug: "%s", Workspace: "%s"',
                $c->getName(),
                $c->getSlug(),
                $c->getWorkspace()?->getName() ?? 'Hors workspace',
            ),
            $channels
        );

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
            . "La date et l'heure actuelles sont : " . $now->format('d/m/Y H:i') . ".\n"
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
            . implode("\n", $channelList) . "\n\n"
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

    private function publishUpdate(string $topic, string $helpMessageId, string $html, string $channelSlug): void
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
