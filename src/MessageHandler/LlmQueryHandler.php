<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Ai\BatchSummarizer;
use App\Ai\ChannelResolver;
use App\Ai\ChannelSummaryBuilder;
use App\Ai\HelpPromptBuilder;
use App\Ai\HelpStreamPublisher;
use App\Ai\IntentClassifier;
use App\Ai\LlmPromptBundle;
use App\Ai\PendingConfirmationService;
use App\Ai\StreamResponseCoordinator;
use App\Ai\ToolActionSigner;
use App\Ai\ToolRegistry;
use App\Ai\ToolRunner;
use App\Ai\ToolRunState;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\LlmQueryMessage;
use App\Repository\ChannelRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use App\Service\LlmService;
use App\Service\RobotDmMessageService;
use App\Service\RobotUserProvider;
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
        private RobotUserProvider $robotUserProvider,
        private RobotDmMessageService $robotDmMessageService,
        #[Autowire(env: 'bool:LLM_TOOLS_ENABLED')]
        private bool $toolsEnabled = true,
        private ?PendingConfirmationService $pendingConfirmationService = null,
        private ?StreamResponseCoordinator $streamCoordinator = null,
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

        $this->streamPublisher->publishStatus(
            $personalTopic,
            $message->getHelpMessageId(),
            'Analyse de la demande... 🔍',
            $channelSlug,
        );

        $channels = $this->channelRepository->findAllForUser($user);
        $workspace = $message->getWorkspaceId() !== null ? $this->workspaceRepository->find($message->getWorkspaceId()) : null;

        try {
            [$intent, $targetChannelSlug] = $this->resolveIntentAndTarget($message, $channelSlug, $channels, $workspace);
            $promptBundle = $this->buildPromptsForIntent(
                $intent,
                $targetChannelSlug,
                $user,
                $channels,
                $workspace,
                $channelSlug,
                $message,
                $personalTopic,
            );

            $streamCoordinator = $this->streamCoordinator ?? new StreamResponseCoordinator($this->streamPublisher);
            $generator = $this->createGenerator($promptBundle->prompt, $promptBundle->systemPrompt, $message, $personalTopic, $state);

            $streamResult = $streamCoordinator->streamAndPublish(
                $generator,
                $personalTopic,
                $message->getHelpMessageId(),
                $promptBundle->prefix,
                $channelSlug,
                static fn(): ?string => $state->pendingConfirmation,
            );

            $accumulatedText = $streamResult['text'];
            $chunkCount = $streamResult['chunkCount'];

            $this->logger->info('LlmQueryHandler completed', [
                'intent' => $intent,
                'channelSlug' => $channelSlug,
                'targetChannelSlug' => $targetChannelSlug,
                'batchCount' => $promptBundle->batchCount,
                'chunkCount' => $chunkCount,
                'toolsExecuted' => $state->toolsExecuted,
                'confirmationRequested' => $state->pendingConfirmation !== null,
                'durationMs' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            $this->robotDmMessageService->persistRobotDmMessage($channelSlug, $promptBundle->prefix . $accumulatedText);
        } catch (\Exception $e) {
            $this->logger->error('LlmQueryHandler failed:', [
                'exception' => $e,
                'helpMessageId' => $message->getHelpMessageId(),
            ]);
            $this->streamPublisher->publishError($personalTopic, $message->getHelpMessageId(), $channelSlug);
        }
    }

    /**
     * @param list<\App\Entity\Channel> $channels
     * @return array{0: string, 1: ?string}
     */
    private function resolveIntentAndTarget(
        LlmQueryMessage $message,
        string $channelSlug,
        array $channels,
        ?Workspace $workspace,
    ): array {
        if ($message->getIntent() !== null) {
            return [$message->getIntent(), $channelSlug];
        }

        if (!$this->robotUserProvider->isRobotDmChannel($channelSlug)) {
            return ['help', $channelSlug];
        }

        $classification = $this->intentClassifier->classify(
            $message->getQuestion(),
            $channels,
            $channelSlug,
            $workspace,
        );
        $this->logger->info('Classification result:', ['classification' => $classification]);

        return [$classification['intent'], $classification['channelSlug'] ?? null];
    }

    /**
     * @param list<\App\Entity\Channel> $channels
     */
    private function buildPromptsForIntent(
        string $intent,
        ?string $targetChannelSlug,
        User $user,
        array $channels,
        ?Workspace $workspace,
        string $channelSlug,
        LlmQueryMessage $message,
        string $personalTopic,
    ): LlmPromptBundle {
        $currentChannel = $this->channelResolver->resolveFromList($channelSlug, $channels)
            ?? $this->channelRepository->findOneBy(['slug' => $channelSlug]);

        [$prompt, $systemPrompt] = $this->helpPromptBuilder->buildDefaultPrompts(
            $message->getQuestion(),
            $channels,
            $workspace,
            $currentChannel,
        );

        $channelName = null;
        $batchCount = 0;

        if ($intent === 'resumer' && $targetChannelSlug !== null && $targetChannelSlug !== '') {
            $targetChannel = $this->channelResolver->resolveFromList($targetChannelSlug, $channels);
            $channelName = $targetChannel ? $targetChannel->getName() : $targetChannelSlug;
            $summaryResult = $this->summaryBuilder->build($user, $channels, $targetChannelSlug);

            $prompt = $summaryResult->prompt;
            $systemPrompt = $summaryResult->systemPrompt;

            if ($summaryResult->requiresBatching()) {
                $batchCount = count($summaryResult->batches);
                [$prompt, $systemPrompt] = $this->batchSummarizer->summarize(
                    $summaryResult->batches,
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
        }

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

        if ($intent === 'help') {
            $prompt = $this->helpPromptBuilder->addConversationContext($prompt, $channelSlug, $message->getQuestion());
        }

        return new LlmPromptBundle($prompt, $systemPrompt, $prefix, $batchCount);
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
