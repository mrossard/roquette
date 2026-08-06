<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Ai\ToolRegistry;
use App\Ai\ToolRunner;
use App\Message\LlmQueryMessage;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Repository\UserChannelReadRepository;
use App\Repository\UserRepository;
use App\Service\LlmService;
use App\Service\MessageFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Store\RetrieverInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
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
        private UserChannelReadRepository $userChannelReadRepository,
        private LlmService $llmService,
        private MessageFormatter $messageFormatter,
        private HubInterface $hub,
        private ParameterBagInterface $parameterBag,
        private EntityManagerInterface $entityManager,
        private string $mercureTopicPrefix,
        private LoggerInterface $logger,
        private \Twig\Environment $twig,
        private RetrieverInterface $retriever,
        private ToolRegistry $toolRegistry,
        private ToolRunner $toolRunner,
        private \App\Repository\WorkspaceRepository $workspaceRepository,
        #[Autowire(env: 'int:LLM_MAX_SUMMARY_MESSAGES')]
        private int $maxSummaryMessages = 100,
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

        // 1. Immediately upon receipt: show "Analyse de la demande... 🔍"
        $initialHtml = $this->messageFormatter->format('Analyse de la demande... 🔍');
        $this->publishUpdate($personalTopic, $message->getHelpMessageId(), $initialHtml, $channelSlug);

        $channels = $this->channelRepository->findAllForUser($user);

        $workspace = null;
        if ($message->getWorkspaceId() !== null) {
            $workspace = $this->workspaceRepository->find($message->getWorkspaceId());
        }

        $currentChannel = $this->channelRepository->findOneBy(['slug' => $channelSlug]);
        if (!$currentChannel) {
            foreach ($channels as $c) {
                if (strtolower($c->getSlug()) === strtolower($channelSlug) || strtolower($c->getName()) === strtolower($channelSlug)) {
                    $currentChannel = $c;
                    break;
                }
            }
        }

        [$prompt, $systemPrompt] = $this->getDefaultHelpPrompts($message->getQuestion(), $channels, $workspace, $currentChannel);

        $intent = $message->getIntent() ?? 'help';
        $channelName = null;
        $batches = null;

        try {
            if ($message->getIntent() === null && str_starts_with($channelSlug, 'dm-robot-roquette-')) {
                $classification = $this->classifyIntent($message->getQuestion(), $channels, $channelSlug, $workspace);
                $this->logger->info('Classification result:', ['classification' => $classification]);
                $intent = $classification['intent'] ?? 'help';
                $targetChannelSlug = $classification['channelSlug'] ?? null;
            } else {
                $targetChannelSlug = $channelSlug;
            }

        if ($intent === 'resumer' && $targetChannelSlug) {
            $targetChannel = null;
            foreach ($channels as $c) {
                if (
                    strtolower($c->getSlug()) === strtolower($targetChannelSlug)
                    || strtolower($c->getName()) === strtolower($targetChannelSlug)
                ) {
                    $targetChannel = $c;
                    break;
                }
            }
            if (!$targetChannel) {
                foreach ($channels as $c) {
                    if (
                        str_contains(strtolower($c->getName()), strtolower($targetChannelSlug))
                        || str_contains(strtolower($c->getSlug()), strtolower($targetChannelSlug))
                    ) {
                        $targetChannel = $c;
                        break;
                    }
                }
            }
            $channelName = $targetChannel ? $targetChannel->getName() : $targetChannelSlug;
            [$prompt, $systemPrompt, $batches] = $this->getSummaryPrompts($user, $channels, $targetChannelSlug);
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
                    $reformulation = "Analyse et résumé du lot {$batchNum}/{$totalBatches}... ⏳";
                    $this->publishUpdate(
                        $personalTopic,
                        $message->getHelpMessageId(),
                        $this->messageFormatter->format($reformulation),
                        $channelSlug,
                    );

                    $batchPrompt = json_encode($batch, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    $intermediateSummaries[] = $this->llmService->generateText($batchPrompt, $intermediateSystemPrompt);
                }

                $reformulation = 'Génération du résumé final combiné... ⏳';
                $this->publishUpdate(
                    $personalTopic,
                    $message->getHelpMessageId(),
                    $this->messageFormatter->format($reformulation),
                    $channelSlug,
                );

                // Prepare combining call
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
            }

            if ($intent === 'help') {
                $prompt = $this->addConversationContext($prompt, $channelSlug, $message->getQuestion());
            }

            $accumulatedText = '';
            $generator = $this->createGenerator($prompt, $systemPrompt, $user, $message, $personalTopic);

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

            $this->publishUpdate($personalTopic, $message->getHelpMessageId(), $formattedHtml, $channelSlug);

            // Persist the message in the database so it is saved only if it is a DM with the robot
            $robotUser = $this->userRepository->findOneBy(['username' => 'robot-roquette']);
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
            $this->logger->error('LlmQueryHandler failed:', ['exception' => $e]);
            $errorHtml =
                '<p style="color: var(--accent-red, #ff5b5b);">Désolé, une erreur est survenue lors de la communication avec le robot d\'aide : '
                . htmlspecialchars($e->getMessage())
                . '</p>';
            $this->publishUpdate($personalTopic, $message->getHelpMessageId(), $errorHtml, $channelSlug);
        }
    }

    private function createGenerator(
        string $prompt,
        string $systemPrompt,
        \App\Entity\User $user,
        LlmQueryMessage $message,
        string $personalTopic,
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
            $user->getId(),
            $message->getWorkspaceId(),
            function (string $toolName) use ($personalTopic, $message): void {
                $this->publishUpdate(
                    $personalTopic,
                    $message->getHelpMessageId(),
                    $this->messageFormatter->format(sprintf("Exécution de l'outil **%s**... ⏳", $toolName)),
                    $message->getChannelSlug(),
                );
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
     * @param \App\Entity\Channel[] $channels
     * @return array{0: string, 1: string}
     */
    private function getDefaultHelpPrompts(
        string $question,
        array $channels,
        ?\App\Entity\Workspace $currentWorkspace = null,
        ?\App\Entity\Channel $currentChannel = null,
    ): array {
        $chunks = [];
        try {
            $retrieved = $this->retriever->retrieve($question, ['limit' => 5]);
            foreach ($retrieved as $doc) {
                if (!$doc->getMetadata()->hasText()) {
                    continue;
                }

                $title = $doc->getMetadata()->hasTitle() ? $doc->getMetadata()->getTitle() : $doc->getId();
                $chunks[] = '### ' . $title . "\n" . $doc->getMetadata()->getText();
            }
        } catch (\Exception) {
        }

        if ([] === $chunks) {
            static $documentation = null;
            if ($documentation === null) {
                $docPath = $this->parameterBag->get('kernel.project_dir') . '/DOC_UTILISATEUR.md';
                $documentation = file_exists($docPath) ? file_get_contents($docPath) : '';
            }
            $context = mb_substr($documentation, 0, 1500);
        } else {
            $context = implode("\n\n---\n\n", $chunks);
        }

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

    /**
     * @param \App\Entity\Channel[] $channels
     */
    private function classifyIntent(string $question, array $channels, string $currentChannelSlug, ?\App\Entity\Workspace $currentWorkspace = null): ?array
    {
        $accessibleChannels = [];
        foreach ($channels as $c) {
            if ($c->getSlug() === $currentChannelSlug) {
                continue;
            }

            $accessibleChannels[] = [
                'name' => $c->getName(),
                'slug' => $c->getSlug(),
                'description' => $c->getDescription(),
                'workspace' => $c->getWorkspace()?->getName() ?? 'Hors workspace',
            ];
        }

        $promptData = [
            'message' => $question,
            'currentWorkspace' => $currentWorkspace?->getName(),
            'channels' => $accessibleChannels,
        ];
        $classificationPrompt = json_encode($promptData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $classificationSystemPrompt =
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

        try {
            $classificationOutput = $this->llmService->generateText($classificationPrompt, $classificationSystemPrompt);
            $jsonText = trim($classificationOutput);
            if (str_starts_with($jsonText, '```')) {
                $jsonText = preg_replace('/^```(?:json)?|```$/', '', $jsonText);
                $jsonText = trim($jsonText);
            }

            return json_decode($jsonText, true);
        } catch (\Exception $e) {
            $this->logger->error('Classification failed:', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * @param \App\Entity\Channel[] $channels
     * @return array{0: string, 1: string, 2: array|null}
     */
    private function getSummaryPrompts(\App\Entity\User $user, array $channels, string $targetChannelSlug): array
    {
        $targetChannel = null;
        foreach ($channels as $c) {
            if (
                !(
                    strtolower($c->getSlug()) === strtolower($targetChannelSlug)
                    || strtolower($c->getName()) === strtolower($targetChannelSlug)
                )
            ) {
                continue;
            }

            $targetChannel = $c;
            break;
        }

        if (!$targetChannel) {
            foreach ($channels as $c) {
                if (
                    !(
                        str_contains(strtolower($c->getName()), strtolower($targetChannelSlug))
                        || str_contains(strtolower($c->getSlug()), strtolower($targetChannelSlug))
                    )
                ) {
                    continue;
                }

                $targetChannel = $c;
                break;
            }
        }

        if ($targetChannel) {
            $activeRead = $this->userChannelReadRepository->findOneBy([
                'user' => $user,
                'channel' => $targetChannel,
            ]);
            $lastReadMessageId = $activeRead?->getLastReadMessage()?->getId();
            $unreadMessages = $this->messageRepository->findUnreadInChannel($targetChannel, $user, $lastReadMessageId);
            $isFallback = false;
            $finalMessages = [];

            if (empty($unreadMessages)) {
                $isFallback = true;
                $unreadMessages = $this->messageRepository
                    ->createQueryBuilder('m')
                    ->where('m.channel = :channel')
                    ->orderBy('m.createdAt', 'DESC')
                    ->setParameter('channel', $targetChannel)
                    ->setMaxResults($this->maxSummaryMessages)
                    ->getQuery()
                    ->getResult();
                $unreadMessages = array_reverse($unreadMessages);
                $finalMessages = $unreadMessages;
            } else {
                $readMessages = [];
                if ($lastReadMessageId !== null) {
                    $readMessages = $this->messageRepository
                        ->createQueryBuilder('m')
                        ->where('m.channel = :channel')
                        ->andWhere('m.parent IS NULL')
                        ->andWhere('m.id <= :lastReadId')
                        ->orderBy('m.id', 'DESC')
                        ->setParameter('channel', $targetChannel)
                        ->setParameter('lastReadId', $lastReadMessageId)
                        ->setMaxResults(5)
                        ->getQuery()
                        ->getResult();
                    $readMessages = array_reverse($readMessages);
                }
                $finalMessages = array_merge($readMessages, $unreadMessages);
            }

            $structuredMessages = [];
            foreach ($finalMessages as $msg) {
                $authorName = $msg->getAuthor() ? $msg->getAuthor()->getUsername() : 'Robot';
                $content = $msg->getContent() ?? '';
                if ($msg->isPoll()) {
                    $content = '[Sondage] ' . $msg->getPoll()->getQuestion();
                }
                $structuredMessages[] = [
                    'date' => $msg->getCreatedAt()->format('Y-m-d H:i'),
                    'auteur' => $authorName,
                    'contenu' => $content,
                ];
            }

            $systemPrompt =
                "Tu es 'Assistant Roquette', un assistant virtuel d'aide pour l'application Roquette."
                . "Ton objectif est d'être un simple observateur des discussions entre les utilisateurs et d'en extraire des synthèses claires, structurées et concises.\n\n"
                . "Tu vas recevoir l'historique des discussions sous format JSON. Chaque objet du tableau représente un message avec sa date, son auteur et son contenu.\n\n"
                . "Consignes de traitement :\n"
                . "- Analyse les données JSON fournies pour en extraire les principaux sujets abordés, les questions résolues ou en cours, ainsi que les décisions importantes.\n"
                . "- Rédige une synthèse globale et thématique de la discussion, claire et concise dans la même langue que la question.\n"
                . '- ATTENTION : Ne fais pas une retranscription brute ou une paraphrase message par message de la discussion. Ne cite pas chaque message un par un. Nous voulons une synthèse condensée des échanges.'
                . "- ATTENTION : tu n'es pas l'un des interlocuteurs et on ne te demande en aucun cas d'intervenir dans la discussion.";

            if (empty($structuredMessages)) {
                $prompt =
                    'Aucun message récent dans le canal #'
                    . $targetChannel->getName()
                    . ". Indique poliment qu'il n'y a rien à résumer.";

                return [$prompt, $systemPrompt, null];
            }

            if (!$isFallback && count($structuredMessages) > $this->maxSummaryMessages) {
                $batches = array_chunk($structuredMessages, $this->maxSummaryMessages);

                return ['', $systemPrompt, $batches];
            }

            $prompt = json_encode($structuredMessages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            return [$prompt, $systemPrompt, null];
        } else {
            $prompt =
                "Explique poliment en français que tu n'as pas trouvé le canal '"
                . $targetChannelSlug
                . "' ou que l'utilisateur n'y est pas inscrit.";
            $systemPrompt = "Tu es 'Assistant Roquette', un assistant virtuel d'aide pour l'application Roquette. Réponds en français.";

            return [$prompt, $systemPrompt, null];
        }
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
