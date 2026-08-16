<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Ai\BatchSummarizer;
use App\Ai\ChannelResolver;
use App\Ai\ChannelSummaryBuilder;
use App\Ai\HelpPromptBuilder;
use App\Ai\HelpStreamPublisher;
use App\Ai\IntentClassifier;
use App\Ai\PendingConfirmationService;
use App\Ai\ToolActionSigner;
use App\Ai\ToolRegistry;
use App\Ai\ToolRunner;
use App\Ai\ToolRunState;
use App\Entity\Message;
use App\Entity\User;
use App\Message\LlmQueryMessage;
use App\Repository\ChannelRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use App\Service\LlmService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class LlmQueryHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ChannelRepository $channelRepository,
        private WorkspaceRepository $workspaceRepository,
        private EntityManagerInterface $entityManager,
        private LlmService $llmService,
        private LoggerInterface $logger,
        private ChannelResolver $channelResolver,
        private IntentClassifier $intentClassifier,
        private ChannelSummaryBuilder $summaryBuilder,
        private HelpPromptBuilder $helpPromptBuilder,
        private BatchSummarizer $batchSummarizer,
        private HelpStreamPublisher $streamPublisher,
        private ToolRegistry $toolRegistry,
        private ToolRunner $toolRunner,
        private ToolActionSigner $toolActionSigner,
        #[Autowire(env: 'bool:LLM_TOOLS_ENABLED')]
        private bool $toolsEnabled = true,
        private ?PendingConfirmationService $pendingConfirmationService = null,
    ) {}

    public function __invoke(LlmQueryMessage $message): void
    {
        $user = $this->userRepository->find($message->getUserId());
        if (!$user) {
            return;
        }

        $personalTopic = $this->streamPublisher->getPersonalTopic($user);
        $channelSlug = $message->getChannelSlug();
        $startedAt = microtime(true);
        $state = new ToolRunState();

        // 1. Immediately upon receipt: show "Analyse de la demande... 🔍"
        $this->streamPublisher->publishStatus(
            $personalTopic,
            $message->getHelpMessageId(),
            'Analyse de la demande... 🔍',
            $channelSlug,
        );

        $channels = $this->channelRepository->findAllForUser($user);

        $workspace = null;
        if ($message->getWorkspaceId() !== null) {
            $workspace = $this->workspaceRepository->find($message->getWorkspaceId());
        }

        $currentChannel = $this->channelResolver->resolveFromList(
            $channelSlug,
            $channels,
        ) ?? $this->channelRepository->findOneBy(['slug' => $channelSlug]);

        [$prompt, $systemPrompt] = $this->helpPromptBuilder->buildDefaultPrompts(
            $message->getQuestion(),
            $channels,
            $workspace,
            $currentChannel,
        );

        $intent = $message->getIntent() ?? 'help';
        $channelName = null;
        $batches = null;
        $targetChannelSlug = null;

        try {
            if ($message->getIntent() === null && str_starts_with($channelSlug, 'dm-' . User::ROBOT_USERNAME . '-')) {
                $classification = $this->intentClassifier->classify(
                    $message->getQuestion(),
                    $channels,
                    $channelSlug,
                    $workspace,
                );
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
            [$reformulation, $prefix] = match ($intent) {
                'resumer' => [
                    'Résumé du canal **#' . ($channelName ?? 'inconnu') . '**... ⏳',
                    '**Résumé du canal #' . ($channelName ?? 'inconnu') . "** :\n\n",
                ],
                'sondage' => ['Création du sondage... ⏳', ''],
                default => ['Traitement de la demande... ⏳', ''],
            };

            $this->streamPublisher->publishStatus(
                $personalTopic,
                $message->getHelpMessageId(),
                $reformulation,
                $channelSlug,
            );

            if ($batches !== null && count($batches) > 1) {
                [$prompt, $systemPrompt] = $this->batchSummarizer->summarize(
                    $batches,
                    onBatchProgress: fn(int $batchNum, int $total) => $this->streamPublisher->publishStatus(
                        $personalTopic,
                        $message->getHelpMessageId(),
                        "Analyse et résumé du lot {$batchNum}/{$total}... ⏳",
                        $channelSlug,
                    ),
                    onFinalProgress: fn() => $this->streamPublisher->publishStatus(
                        $personalTopic,
                        $message->getHelpMessageId(),
                        'Génération du résumé final combiné... ⏳',
                        $channelSlug,
                    ),
                );
            }

            if ($intent === 'help') {
                $prompt = $this->helpPromptBuilder->addConversationContext($prompt, $channelSlug, $message->getQuestion());
            }

            $accumulatedText = '';
            $generator = $this->createGenerator($prompt, $systemPrompt, $message, $personalTopic, $state);

            $chunkCount = 0;
            foreach ($generator as $chunk) {
                $accumulatedText .= $chunk;
                $chunkCount++;

                if ($chunkCount <= 3 || ($chunkCount % 3) === 0) {
                    $this->streamPublisher->publishStreamText(
                        $personalTopic,
                        $message->getHelpMessageId(),
                        $prefix,
                        $accumulatedText,
                        $channelSlug,
                    );
                }
            }

            $this->streamPublisher->publishStreamText(
                $personalTopic,
                $message->getHelpMessageId(),
                $prefix,
                $accumulatedText,
                $channelSlug,
                $state->pendingConfirmation,
            );

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
            $this->persistRobotDmMessage($message->getChannelSlug(), $prefix . $accumulatedText);
        } catch (\Exception $e) {
            $this->logger->error('LlmQueryHandler failed:', [
                'exception' => $e,
                'helpMessageId' => $message->getHelpMessageId(),
            ]);
            $this->streamPublisher->publishError($personalTopic, $message->getHelpMessageId(), $channelSlug);
        }
    }

    private function persistRobotDmMessage(string $channelSlug, string $content): void
    {
        if (!str_starts_with($channelSlug, 'dm-' . User::ROBOT_USERNAME . '-')) {
            return;
        }

        $robotUser = $this->userRepository->findOneBy(['username' => User::ROBOT_USERNAME]);
        $channel = $this->channelRepository->findOneBy(['slug' => $channelSlug]);
        if (!$robotUser || !$channel) {
            return;
        }

        $dbMessage = new Message();
        $dbMessage->setAuthor($robotUser);
        $dbMessage->setChannel($channel);
        $dbMessage->setContent($content);
        $dbMessage->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($dbMessage);
        $this->entityManager->flush();
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
                $this->streamPublisher->publishStatus(
                    $personalTopic,
                    $message->getHelpMessageId(),
                    sprintf("Exécution de l'outil **%s**... ⏳", $toolName),
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
                $this->pendingConfirmationService?->savePendingConfirmation(
                    $message->getUserId(),
                    $state->pendingConfirmation,
                    $message->getChannelSlug(),
                );
            },
        );
    }
}
