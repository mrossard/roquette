<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\LlmQueryMessage;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Repository\UserChannelReadRepository;
use App\Repository\UserRepository;
use App\Service\LlmService;
use App\Service\MessageFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class LlmQueryHandler
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ChannelRepository $channelRepository,
        private readonly MessageRepository $messageRepository,
        private readonly UserChannelReadRepository $userChannelReadRepository,
        private readonly LlmService $llmService,
        private readonly MessageFormatter $messageFormatter,
        private readonly HubInterface $hub,
        private readonly ParameterBagInterface $parameterBag,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $mercureTopicPrefix,
        private readonly LoggerInterface $logger,
        private readonly \Twig\Environment $twig,
        #[Autowire(env: 'int:LLM_MAX_SUMMARY_MESSAGES')]
        private readonly int $maxSummaryMessages = 100,
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

        [$prompt, $systemPrompt] = $this->getDefaultHelpPrompts($message->getQuestion());

        $intent = 'help';
        $channelName = null;
        $batches = null;

        if (str_starts_with($channelSlug, 'dm-robot-roquette-')) {
            $channels = $this->channelRepository->findAllForUser($user);
            $classification = $this->classifyIntent($message->getQuestion(), $channels, $channelSlug);

            $this->logger->info('Classification result:', ['classification' => $classification]);

            $intent = $classification['intent'] ?? 'help';
            $targetChannelSlug = $classification['channelSlug'] ?? null;

            if ($intent === 'resumer' && $targetChannelSlug) {
                // Find the target channel
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
        }

        // 2. Once classification is done: reformulate the request based on intent
        if ($intent === 'resumer') {
            $reformulation = 'Résumé du canal **#' . ($channelName ?? 'inconnu') . '**... ⏳';
            $prefix = '**Résumé du canal #' . ($channelName ?? 'inconnu') . "** :\n\n";
        } else {
            $reformulation = 'Recherche dans la documentation... ⏳';
            $prefix = "**Recherche dans la documentation** :\n\n";
        }

        $this->publishUpdate(
            $personalTopic,
            $message->getHelpMessageId(),
            $this->messageFormatter->format($reformulation),
            $channelSlug,
        );

        try {
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

            $accumulatedText = '';
            $generator = $this->llmService->generateTextStream($prompt, $systemPrompt);

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
            $errorHtml =
                '<p style="color: var(--accent-red, #ff5b5b);">Désolé, une erreur est survenue lors de la communication avec le robot d\'aide : '
                . htmlspecialchars($e->getMessage())
                . '</p>';
            $this->publishUpdate($personalTopic, $message->getHelpMessageId(), $errorHtml, $channelSlug);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function getDefaultHelpPrompts(string $question): array
    {
        $projectDir = $this->parameterBag->get('kernel.project_dir');
        $docPath = $projectDir . '/DOC_UTILISATEUR.md';
        $documentation = file_exists($docPath) ? file_get_contents($docPath) : '';

        $systemPrompt =
            "Tu es 'Assistant Roquette', un assistant virtuel d'aide pour l'application Roquette. "
            . "Réponds dans la langue de la question aux questions des utilisateurs en t'appuyant uniquement sur la documentation utilisateur fournie ci-dessous. "
            . 'Sois concis et précis dans ta réponse. '
            . "Si la réponse n'est pas dans la documentation, réponds poliment que tu ne sais pas car cela ne figure pas dans le guide utilisateur.\n\n"
            . "Documentation utilisateur :\n"
            . $documentation;

        return [$question, $systemPrompt];
    }

    /**
     * @param \App\Entity\Channel[] $channels
     */
    private function classifyIntent(string $question, array $channels, string $currentChannelSlug): ?array
    {
        $accessibleChannels = [];
        foreach ($channels as $c) {
            if ($c->getSlug() !== $currentChannelSlug) {
                $accessibleChannels[] = [
                    'name' => $c->getName(),
                    'slug' => $c->getSlug(),
                    'description' => $c->getDescription(),
                ];
            }
        }

        $promptData = [
            'message' => $question,
            'channels' => $accessibleChannels,
        ];
        $classificationPrompt = json_encode($promptData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $classificationSystemPrompt =
            "Tu es un outil d'analyse d'intention d'utilisateur pour l'application Roquette. "
            . "L'entrée qui te sera fournie sous forme de prompt est un objet JSON contenant :\n"
            . "- \"message\" : Le message ou la question écrite par l'utilisateur.\n"
            . "- \"channels\" : La liste des canaux auxquels l'utilisateur a accès, chaque canal ayant un \"name\", \"slug\", et \"description\".\n\n"
            . "Ton rôle unique est de classifier le message pour déterminer l'intention de l'utilisateur et d'extraire le slug du canal cible si nécessaire.\n\n"
            . "Les intentions possibles sont :\n"
            . "1. \"resumer\" : L'utilisateur demande explicitement un résumé des messages récents d'un canal (ex. : 'résume le canal général', 'fais-moi une synthèse de htmx', 'qu'est-ce qui s'est dit sur mercure', etc.). Si l'utilisateur mentionne le nom ou le slug d'un des canaux fournis pour en obtenir un résumé ou avoir des nouvelles, c'est l'intention \"resumer\".\n"
            . "2. \"help\" : L'utilisateur pose une question générale, demande de l'aide, ou veut savoir comment faire quelque chose dans l'application (ex. : 'comment créer un canal', 'aide-moi', etc.).\n\n"
            . "Tu dois répondre STRICTEMENT sous format JSON avec la structure suivante, sans aucun autre texte (pas de markdown, pas de blocs de code) :\n"
            . "{\n"
            . "  \"intent\": \"resumer\" ou \"help\",\n"
            . "  \"channelSlug\": \"le slug du canal à résumer\" (ou null si l'intention est \"help\" ou si le canal n'a pas pu être identifié)\n"
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
