<?php

declare(strict_types=1);

namespace App\Service;

use App\Ai\PendingConfirmationService;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Message\LlmQueryMessage;
use App\Message\ModerateMessageMessage;
use App\Message\ScanFileMessage;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class MessagePublishService
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisher $mercurePublisher,
        private readonly FileUploadService $fileUploadService,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly MessageRenderer $messageRenderer,
        private readonly Environment $twig,
        private readonly LlmRateLimiter $llmRateLimiter,
        private readonly RobotUserProvider $robotUserProvider,
        private readonly PollFactory $pollFactory,
        private readonly ?PendingConfirmationService $pendingConfirmationService = null,
    ) {}

    /**
     * @param array<int, string>|null $pollOptions
     */
    public function publish(
        Channel $channel,
        User $currentUser,
        string $messageText,
        ?UploadedFile $file = null,
        ?string $pollQuestion = null,
        ?array $pollOptions = null,
        bool $pollAllowMultiple = false,
        ?int $replyToId = null,
        ?int $workspaceId = null,
    ): PublishResult {
        $isPoll = $pollQuestion !== null && $pollQuestion !== '';

        if (trim($messageText) === '' && !$file && !$isPoll) {
            return PublishResult::empty($channel);
        }

        if (!$isPoll && $file === null && $messageText !== '') {
            $confirmationResult = $this->tryHandleConfirmation($currentUser, $channel, $messageText);
            if ($confirmationResult !== null) {
                return $confirmationResult;
            }
        }

        $pollValidation = $this->validatePoll($isPoll, $pollOptions, $channel);
        if ($pollValidation !== null) {
            return $pollValidation;
        }

        $isDmWithRobot = $channel->getSlug() === $this->robotUserProvider->getDmChannelSlug($currentUser);

        if ($this->isRobotMentioned($messageText) && !$isDmWithRobot) {
            return $this->handleRobotMentionInChannel($channel, $currentUser, $messageText, $workspaceId);
        }

        $llmLimitCheck = $this->checkRobotDmLlmRateLimit($isDmWithRobot, $isPoll, $file, $currentUser, $channel);
        if ($llmLimitCheck !== null) {
            return $llmLimitCheck;
        }

        $builtMessage = $this->tryBuildMessage($channel, $currentUser, $messageText, $file, $replyToId);
        if ($builtMessage instanceof PublishResult) {
            return $builtMessage;
        }
        $message = $builtMessage;

        if ($isPoll) {
            $poll = $this->pollFactory->createPoll($message, (string) $pollQuestion, $pollOptions ?? [], $pollAllowMultiple);
            $this->entityManager->persist($poll);
        }

        $renderedHtml = $this->persistAndBroadcast(
            $message,
            $channel,
            $currentUser,
            $file !== null,
            $isPoll,
            $pollQuestion,
            $messageText,
            $isDmWithRobot,
            $workspaceId,
        );

        return PublishResult::ok($channel, $message, $renderedHtml);
    }

    private function tryBuildMessage(
        Channel $channel,
        User $currentUser,
        string $messageText,
        ?UploadedFile $file,
        ?int $replyToId,
    ): Message|PublishResult {
        try {
            return $this->buildMessage($channel, $currentUser, $messageText, $file, $replyToId);
        } catch (\InvalidArgumentException $e) {
            return PublishResult::error(
                error: $e->getMessage(),
                channel: $channel,
                statusCode: 422,
            );
        }
    }

    private function persistAndBroadcast(
        Message $message,
        Channel $channel,
        User $currentUser,
        bool $hasFile,
        bool $isPoll,
        ?string $pollQuestion,
        string $messageText,
        bool $isDmWithRobot,
        ?int $workspaceId,
    ): string {
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->dispatchPostPublishAsyncTasks($message, $hasFile, $isPoll, $channel->isDm(), $isDmWithRobot, $messageText, $workspaceId);

        return $this->renderAndBroadcastMessage($channel, $message, $currentUser, $isPoll, $pollQuestion, $messageText);
    }

    /**
     * @param array<int, string>|null $pollOptions
     */
    private function validatePoll(bool $isPoll, ?array $pollOptions, Channel $channel): ?PublishResult
    {
        if ($isPoll && !$this->pollFactory->hasValidOptions($pollOptions)) {
            return PublishResult::error(
                error: $this->translator->trans(PollFactory::ERROR_MIN_OPTIONS),
                channel: $channel,
                statusCode: 400,
            );
        }

        return null;
    }

    private function checkRobotDmLlmRateLimit(
        bool $isDmWithRobot,
        bool $isPoll,
        ?UploadedFile $file,
        User $currentUser,
        Channel $channel,
    ): ?PublishResult {
        if ($isDmWithRobot && !$isPoll && $file === null && !$this->llmRateLimiter->consume($currentUser)) {
            return PublishResult::error(
                error: $this->translator->trans(LlmRateLimiter::MESSAGE_KEY),
                channel: $channel,
                statusCode: Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        return null;
    }

    private function tryHandleConfirmation(User $currentUser, Channel $channel, string $messageText): ?PublishResult
    {
        if ($this->pendingConfirmationService === null) {
            return null;
        }

        $pendingToken = $this->pendingConfirmationService->getPendingConfirmation($currentUser, $channel->getSlug());
        if ($pendingToken !== null && $this->pendingConfirmationService->isConfirmation($messageText, $pendingToken, $currentUser)) {
            if ($this->pendingConfirmationService->executeConfirmation($pendingToken, $currentUser)) {
                return PublishResult::ok($channel, null, '');
            }
        }

        return null;
    }

    private function handleRobotMentionInChannel(
        Channel $channel,
        User $currentUser,
        string $messageText,
        ?int $workspaceId,
    ): PublishResult {
        if (!$this->llmRateLimiter->consume($currentUser)) {
            return PublishResult::error(
                error: $this->translator->trans(LlmRateLimiter::MESSAGE_KEY),
                channel: $channel,
                statusCode: Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        // When querying the robot in a channel, do NOT persist the message in DB nor broadcast it to everyone.
        // Dispatch async LLM processing with the user's question, which will stream privately back to the user.
        $helpMessageId = 'help-' . uniqid();
        $this->messageBus->dispatch(
            new LlmQueryMessage($messageText, $currentUser->getId(), $channel->getSlug(), $helpMessageId, workspaceId: $workspaceId),
        );

        $tempMessage = new Message();
        $tempMessage->setAuthor($currentUser);
        $tempMessage->setChannel($channel);

        $oobHtml = $this->twig->render('dashboard/_help_message_oob.html.twig', [
            'answer' => null,
            'question' => $messageText,
            'helpMessageId' => $helpMessageId,
            'activeChannel' => $channel,
            'timestamp' => new \DateTime(),
        ]);

        return PublishResult::ok($channel, $tempMessage, $oobHtml);
    }

    private function buildMessage(
        Channel $channel,
        User $currentUser,
        string $messageText,
        ?UploadedFile $file,
        ?int $replyToId,
    ): Message {
        $message = new Message();
        $message->setAuthor($currentUser);
        $message->setChannel($channel);

        if ($replyToId !== null && !$channel->isTodoList()) {
            $parentMessage = $this->messageRepository->find($replyToId);
            if ($parentMessage !== null && $parentMessage->getChannel()->getId() === $channel->getId()) {
                $message->setParentMessage($parentMessage);
            }
        }

        $message->setContent(trim($messageText) === '' ? null : $messageText);

        if ($file !== null) {
            $this->fileUploadService->uploadAndAttachToMessage($file, $message);
            $message->setVirusScanStatus('pending');
        }

        return $message;
    }

    private function dispatchPostPublishAsyncTasks(
        Message $message,
        bool $hasFile,
        bool $isPoll,
        bool $isDm,
        bool $isDmWithRobot,
        string $messageText,
        ?int $workspaceId,
    ): void {
        if ($hasFile) {
            $this->messageBus->dispatch(new ScanFileMessage($message->getId()));
        }

        if ($message->getContent() !== null && !$isPoll && !$isDm) {
            $this->messageBus->dispatch(new ModerateMessageMessage($message->getId()));
        }

        if ($isDmWithRobot && !$isPoll && !$hasFile) {
            $this->messageBus->dispatch(
                new LlmQueryMessage($messageText, $message->getAuthor()->getId(), $message->getChannel()->getSlug(), 'help-' . uniqid(), workspaceId: $workspaceId),
            );
        }
    }

    private function renderAndBroadcastMessage(
        Channel $channel,
        Message $message,
        User $currentUser,
        bool $isPoll,
        ?string $pollQuestion,
        string $messageText,
    ): string {
        $renderedHtml = $this->messageRenderer->renderFeedItem($message);

        $previousMessages = $this->messageRepository->findLatestInChannel($channel, 1, $message->getId());
        if ($previousMessages !== [] && !$channel->isTodoList()) {
            $renderedHtml = $this->maybePrependDaySeparator($previousMessages[0], $message, $renderedHtml);
        }

        $this->mercurePublisher->publishNewMessage(
            $channel,
            $message,
            $currentUser,
            $isPoll ? 'Sondage : ' . $pollQuestion : $messageText,
            $renderedHtml,
        );

        return $renderedHtml;
    }

    private function isRobotMentioned(string $messageText): bool
    {
        $robot = $this->robotUserProvider->getRobotUser();
        if ($robot === null) {
            return false;
        }

        $rawUsername = $robot->getUsername();
        $name = ($rawUsername !== null && $rawUsername !== '') ? $rawUsername : User::ROBOT_USERNAME;
        $tokenAlias = strtok($name, '-');
        $alias = ($tokenAlias !== false && $tokenAlias !== '') ? $tokenAlias : $name;

        return preg_match(
            '/@(?:' . preg_quote($name, '/') . '|' . preg_quote($alias, '/') . ')(?![\p{L}\p{N}-])/iu',
            $messageText,
        ) === 1;
    }

    private function maybePrependDaySeparator(
        Message $previousMessage,
        Message $newMessage,
        string $renderedHtml,
    ): string {
        $previousDate = $previousMessage->getCreatedAt()->format('Y-m-d');
        $newDate = $newMessage->getCreatedAt()->format('Y-m-d');

        if ($previousDate === $newDate) {
            return $renderedHtml;
        }

        $today = new \DateTimeImmutable()->format('Y-m-d');
        $yesterday = new \DateTimeImmutable('-1 day')->format('Y-m-d');
        $label = match ($newDate) {
            $today => "Aujourd'hui",
            $yesterday => 'Hier',
            default => $newMessage->getCreatedAt()->format('d/m/Y'),
        };

        return $this->twig->render('dashboard/_day_separator.html.twig', ['label' => $label]) . "\n" . $renderedHtml;
    }
}
