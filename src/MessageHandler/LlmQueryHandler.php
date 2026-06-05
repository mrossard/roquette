<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\LlmQueryMessage;
use App\Repository\UserRepository;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Repository\UserChannelReadRepository;
use App\Service\LlmService;
use App\Service\MessageFormatter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Doctrine\ORM\EntityManagerInterface;

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
    ) {}

    public function __invoke(LlmQueryMessage $message): void
    {
        $user = $this->userRepository->find($message->getUserId());
        if (!$user) {
            return;
        }

        $personalTopic = $this->mercureTopicPrefix.'/users/'.$user->getUsername();

        // 1. Immediately upon receipt: show "Analyse de la demande... 🔍"
        $initialHtml = $this->messageFormatter->format('Analyse de la demande... 🔍');
        $this->publishUpdate($personalTopic, $message->getHelpMessageId(), $message->getChannelSlug(), $initialHtml);

        [$prompt, $systemPrompt] = $this->getDefaultHelpPrompts($message->getQuestion());

        $channelSlug = $message->getChannelSlug();
        $intent = 'help';
        $channelName = null;

        if (str_starts_with($channelSlug, 'dm-robot-roquette-')) {
            $channels = $this->channelRepository->findAllForUser($user);
            $classification = $this->classifyIntent($message->getQuestion(), $channels, $channelSlug);

            $intent = $classification['intent'] ?? 'help';
            $targetChannelSlug = $classification['channelSlug'] ?? null;

            if ($intent === 'resumer' && $targetChannelSlug) {
                // Find the target channel
                $targetChannel = null;
                foreach ($channels as $c) {
                    if (strtolower($c->getSlug()) === strtolower($targetChannelSlug) || strtolower(
                            $c->getName(),
                        ) === strtolower($targetChannelSlug)) {
                        $targetChannel = $c;
                        break;
                    }
                }
                if (!$targetChannel) {
                    foreach ($channels as $c) {
                        if (str_contains(strtolower($c->getName()), strtolower($targetChannelSlug)) || str_contains(
                                strtolower($c->getSlug()),
                                strtolower($targetChannelSlug),
                            )) {
                            $targetChannel = $c;
                            break;
                        }
                    }
                }
                $channelName = $targetChannel ? $targetChannel->getName() : $targetChannelSlug;
                [$prompt, $systemPrompt] = $this->getSummaryPrompts($user, $channels, $targetChannelSlug);
            }
        }

        // 2. Once classification is done: reformulate the request based on intent
        if ($intent === 'resumer') {
            $reformulation = "Résumé du canal **#".($channelName ?? 'inconnu')."**... ⏳";
            $prefix = "**Résumé du canal #".($channelName ?? 'inconnu')."** :\n\n";
        } else {
            $reformulation = "Recherche dans la documentation... ⏳";
            $prefix = "**Recherche dans la documentation** :\n\n";
        }

        $this->publishUpdate(
            $personalTopic,
            $message->getHelpMessageId(),
            $message->getChannelSlug(),
            $this->messageFormatter->format($reformulation),
        );

        try {
            $accumulatedText = '';
            $generator = $this->llmService->generateTextStream($prompt, $systemPrompt);

            $chunkCount = 0;
            foreach ($generator as $chunk) {
                $accumulatedText .= $chunk;
                $chunkCount++;

                if ($chunkCount <= 3 || $chunkCount % 3 === 0) {
                    $formattedHtml = $this->messageFormatter->format($prefix.$accumulatedText);
                    $this->publishUpdate(
                        $personalTopic,
                        $message->getHelpMessageId(),
                        $message->getChannelSlug(),
                        $formattedHtml,
                    );
                }
            }

            $formattedHtml = $this->messageFormatter->format($prefix.$accumulatedText);
            $this->publishUpdate(
                $personalTopic,
                $message->getHelpMessageId(),
                $message->getChannelSlug(),
                $formattedHtml,
            );

            // Persist the message in the database so it is saved
            $robotUser = $this->userRepository->findOneBy(['username' => 'robot-roquette']);
            $channel = $this->channelRepository->findOneBy(['slug' => $message->getChannelSlug()]);
            if ($robotUser && $channel) {
                $dbMessage = new \App\Entity\Message();
                $dbMessage->setAuthor($robotUser);
                $dbMessage->setChannel($channel);
                $dbMessage->setContent($prefix.$accumulatedText);
                $dbMessage->setCreatedAt(new \DateTimeImmutable());
                $this->entityManager->persist($dbMessage);
                $this->entityManager->flush();
            }
        } catch (\Exception $e) {
            $errorHtml = '<p style="color: var(--accent-red, #ff5b5b);">Désolé, une erreur est survenue lors de la communication avec le robot d\'aide : '.htmlspecialchars(
                    $e->getMessage(),
                ).'</p>';
            $this->publishUpdate($personalTopic, $message->getHelpMessageId(), $message->getChannelSlug(), $errorHtml);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function getDefaultHelpPrompts(string $question): array
    {
        $projectDir = $this->parameterBag->get('kernel.project_dir');
        $docPath = $projectDir.'/DOC_UTILISATEUR.md';
        $documentation = file_exists($docPath) ? file_get_contents($docPath) : '';

        $systemPrompt = "Tu es 'Assistant Roquette', un assistant virtuel d'aide pour l'application Roquette. "
            ."Réponds dans la langue de la question aux questions des utilisateurs en t'appuyant uniquement sur la documentation utilisateur fournie ci-dessous. "
            ."Sois concis et précis dans ta réponse. "
            ."Si la réponse n'est pas dans la documentation, réponds poliment que tu ne sais pas car cela ne figure pas dans le guide utilisateur.\n\n"
            ."Documentation utilisateur :\n".$documentation;

        return [$question, $systemPrompt];
    }

    /**
     * @param \App\Entity\Channel[] $channels
     */
    private function classifyIntent(string $question, array $channels, string $currentChannelSlug): ?array
    {
        $channelListStr = '';
        foreach ($channels as $c) {
            if ($c->getSlug() !== $currentChannelSlug) {
                $channelListStr .= sprintf(
                    "- Nom: %s, Slug: %s, Description: %s\n",
                    $c->getName(),
                    $c->getSlug(),
                    $c->getDescription(),
                );
            }
        }

        $classificationPrompt = "L'utilisateur a écrit au robot d'aide : \"".$question."\"\n\n"
            ."Voici la liste des canaux auxquels l'utilisateur a accès :\n"
            .$channelListStr."\n"
            ."Tu dois déterminer l'intention de l'utilisateur. Les deux intentions possibles sont :\n"
            ."1. 'resumer' : L'utilisateur demande explicitement un résumé des messages récents d'un canal (par exemple : 'résume le canal général', 'fais-moi une synthèse de htmx', 'qu'est-ce qui s'est dit sur mercure', etc.). Si l'utilisateur mentionne le nom ou le slug d'un des canaux ci-dessus pour en avoir un résumé ou des nouvelles, c'est l'intention 'resumer'.\n"
            ."2. 'help' : L'utilisateur pose une question générale, demande de l'aide, ou veut savoir comment faire quelque chose dans l'application (par exemple: 'comment créer un canal', 'aide-moi', etc.).\n\n"
            ."Réponds strictement sous format JSON avec la structure suivante, sans aucun autre texte :\n"
            ."{\n"
            ."  \"intent\": \"resumer\" ou \"help\",\n"
            ."  \"channelSlug\": \"le slug du canal à résumer\" ou null\n"
            ."}";

        $classificationSystemPrompt = "Tu es un outil d'analyse d'intention d'utilisateur. Ton unique rôle est de classifier le message et d'extraire le slug du canal cible sous forme de JSON strict, sans formuler de réponse à la question ou au message de l'utilisateur.";

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
     * @return array{0: string, 1: string}
     */
    private function getSummaryPrompts(\App\Entity\User $user, array $channels, string $targetChannelSlug): array
    {
        $targetChannel = null;
        foreach ($channels as $c) {
            if (strtolower($c->getSlug()) === strtolower($targetChannelSlug) || strtolower(
                    $c->getName(),
                ) === strtolower($targetChannelSlug)) {
                $targetChannel = $c;
                break;
            }
        }

        if (!$targetChannel) {
            foreach ($channels as $c) {
                if (str_contains(strtolower($c->getName()), strtolower($targetChannelSlug)) || str_contains(
                        strtolower($c->getSlug()),
                        strtolower($targetChannelSlug),
                    )) {
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

            if (empty($unreadMessages)) {
                $unreadMessages = $this->messageRepository
                    ->createQueryBuilder('m')
                    ->where('m.channel = :channel')
                    ->andWhere('m.parent IS NULL')
                    ->orderBy('m.createdAt', 'DESC')
                    ->setParameter('channel', $targetChannel)
                    ->setMaxResults(20)
                    ->getQuery()
                    ->getResult();
                $unreadMessages = array_reverse($unreadMessages);
            }

            $structuredMessages = [];
            foreach ($unreadMessages as $msg) {
                $authorName = $msg->getAuthor() ? $msg->getAuthor()->getUsername() : 'Robot';
                $content = $msg->getContent() ?? '';
                if ($msg->isPoll()) {
                    $content = '[Sondage] '.$msg->getPoll()->getQuestion();
                }
                $structuredMessages[] = [
                    'date' => $msg->getCreatedAt()->format('Y-m-d H:i'),
                    'auteur' => $authorName,
                    'contenu' => $content,
                ];
            }

            $systemPrompt = "Tu es 'Assistant Roquette', un assistant virtuel d'aide pour l'application Roquette. "
                ."Ton objectif est de rédiger des synthèses de discussions de groupe claires, structurées et concises en français.\n\n"
                ."Tu vas recevoir l'historique des discussions sous format JSON. Chaque objet du tableau représente un message avec sa date, son auteur et son contenu.\n\n"
                ."Consignes de traitement :\n"
                ."- Analyse les données JSON fournies pour en extraire les principaux sujets abordés, les questions résolues ou en cours, ainsi que les décisions importantes.\n"
                ."- Rédige une synthèse globale et thématique de la discussion, claire et concise en français.\n"
                ."- ATTENTION : Ne fais pas une retranscription brute ou une paraphrase message par message de la discussion. Ne cite pas chaque message un par un. Nous voulons une synthèse condensée des échanges.";

            if (empty($structuredMessages)) {
                $prompt = "Aucun message récent dans le canal #".$targetChannel->getName(
                    ).". Indique poliment qu'il n'y a rien à résumer.";
            } else {
                $prompt = json_encode($structuredMessages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        } else {
            $prompt = "Explique poliment en français que tu n'as pas trouvé le canal '".$targetChannelSlug."' ou que l'utilisateur n'y est pas inscrit.";
            $systemPrompt = "Tu es 'Assistant Roquette', un assistant virtuel d'aide pour l'application Roquette. Réponds en français.";
        }

        return [$prompt, $systemPrompt];
    }

    private function publishUpdate(string $topic, string $helpMessageId, string $channelSlug, string $html): void
    {
        $update = new Update(
            $topic,
            json_encode([
                'type' => 'help_stream_update',
                'helpMessageId' => $helpMessageId,
                'channelSlug' => $channelSlug,
                'html' => $html,
            ]),
            true,
        );

        $this->hub->publish($update);
    }
}
