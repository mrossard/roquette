<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Message\IndexMessageMessage;
use App\Message\ModerateMessageMessage;
use App\Message\ScanFileMessage;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class MessagePublishService
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisher $mercurePublisher,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly MessageRenderer $messageRenderer,
        private readonly Environment $twig,
        private readonly PollFactory $pollFactory,
        private readonly MessageFactory $messageFactory,
        private readonly RobotInteractionService $robotInteractionService,
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

        if ($isPoll && !$this->pollFactory->hasValidOptions($pollOptions)) {
            return PublishResult::error(
                error: $this->translator->trans(PollFactory::ERROR_MIN_OPTIONS),
                channel: $channel,
                statusCode: 400,
            );
        }

        if (!$isPoll) {
            $robotResult = $this->checkRobotInteractions($channel, $currentUser, $messageText, $file, $workspaceId);
            if ($robotResult !== null) {
                return $robotResult;
            }
        }

        try {
            $message = $this->messageFactory->create($channel, $currentUser, $messageText, $file, $replyToId);
        } catch (\InvalidArgumentException $e) {
            return PublishResult::error(error: $e->getMessage(), channel: $channel, statusCode: 422);
        }

        if ($isPoll) {
            $poll = $this->pollFactory->createPoll(
                $message,
                (string) $pollQuestion,
                $pollOptions ?? [],
                $pollAllowMultiple,
            );
            $this->entityManager->persist($poll);
        }

        $renderedHtml = $this->persistAndBroadcast($message, $channel, $currentUser, $messageText, $workspaceId);

        return PublishResult::ok($channel, $message, $renderedHtml);
    }

    private function checkRobotInteractions(
        Channel $channel,
        User $currentUser,
        string $messageText,
        ?UploadedFile $file,
        ?int $workspaceId,
    ): ?PublishResult {
        if ($file === null && $messageText !== '') {
            $confirmationResult = $this->robotInteractionService->tryHandleConfirmation(
                $currentUser,
                $channel,
                $messageText,
            );
            if ($confirmationResult !== null) {
                return $confirmationResult;
            }
        }

        $isDmWithRobot = $this->robotInteractionService->isRobotDm($channel, $currentUser);

        if ($this->robotInteractionService->isRobotMentioned($messageText) && !$isDmWithRobot) {
            return $this->robotInteractionService->handleRobotMentionInChannel(
                $channel,
                $currentUser,
                $messageText,
                $workspaceId,
            );
        }

        if ($file === null) {
            return $this->robotInteractionService->checkRobotDmLlmRateLimit($currentUser, $channel);
        }

        return null;
    }

    private function persistAndBroadcast(
        Message $message,
        Channel $channel,
        User $currentUser,
        string $messageText,
        ?int $workspaceId,
    ): string {
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->dispatchPostPublishAsyncTasks($message, $channel, $currentUser, $messageText, $workspaceId);

        return $this->renderAndBroadcastMessage($channel, $message, $currentUser, $messageText);
    }

    private function dispatchPostPublishAsyncTasks(
        Message $message,
        Channel $channel,
        User $currentUser,
        string $messageText,
        ?int $workspaceId,
    ): void {
        if ($message->getFilePath() !== null) {
            $this->messageBus->dispatch(new ScanFileMessage((int) $message->getId()));
        }

        if ($message->getContent() !== null && !$message->isPoll() && !$channel->isDm()) {
            $this->messageBus->dispatch(new ModerateMessageMessage((int) $message->getId()));
        }

        if ($message->getContent() !== null && trim($message->getContent()) !== '' && !$message->isPoll()) {
            $this->messageBus->dispatch(new IndexMessageMessage((int) $message->getId()));
        }

        if (
            !$message->isPoll()
            && $message->getFilePath() === null
            && $this->robotInteractionService->isRobotDm($channel, $currentUser)
        ) {
            $this->robotInteractionService->dispatchRobotDmQuery($message, $messageText, $workspaceId);
        }
    }

    private function renderAndBroadcastMessage(
        Channel $channel,
        Message $message,
        User $currentUser,
        string $messageText,
    ): string {
        $renderedHtml = $this->messageRenderer->renderFeedItem($message);

        $previousMessage = $this->messageRepository->findPreviousMessage($channel, (int) $message->getId());
        if ($previousMessage !== null && !$channel->isTodoList()) {
            $renderedHtml = $this->maybePrependDaySeparator($previousMessage, $message, $renderedHtml);
        }

        $notificationText = $message->isPoll()
            ? 'Sondage : ' . ($message->getPoll()?->getQuestion() ?? '')
            : $messageText;

        $this->mercurePublisher->publishNewMessage($channel, $message, $currentUser, $notificationText, $renderedHtml);

        return $renderedHtml;
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
