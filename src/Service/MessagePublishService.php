<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Message\LlmQueryMessage;
use App\Message\ModerateMessageMessage;
use App\Message\ScanFileMessage;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class MessagePublishService
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisher $mercurePublisher,
        private readonly FileUploadService $fileUploadService,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly MessageRenderer $messageRenderer,
        private readonly Environment $twig,
        #[Autowire(service: 'limiter.llm_api')]
        private readonly RateLimiterFactoryInterface $llmRateLimiter,
        private readonly ?\App\Ai\PendingConfirmationService $pendingConfirmationService = null,
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
            return new PublishResult(success: false, channel: $channel);
        }

        if (!$isPoll && $file === null && $messageText !== '' && $this->pendingConfirmationService !== null) {
            $pendingToken = $this->pendingConfirmationService->getPendingConfirmation($currentUser, $channel->getSlug());
            if ($pendingToken !== null && $this->pendingConfirmationService->isConfirmation($messageText, $pendingToken, $currentUser)) {
                if ($this->pendingConfirmationService->executeConfirmation($pendingToken, $currentUser)) {
                    return new PublishResult(success: true, channel: $channel, message: null, renderedHtml: '');
                }
            }
        }

        if ($isPoll && $pollOptions !== null && count($pollOptions) < 2) {
            return new PublishResult(
                success: false,
                channel: $channel,
                error: $this->translator->trans('Un sondage requiert au moins 2 options.'),
                statusCode: 400,
            );
        }

        $message = new Message();
        $message->setAuthor($currentUser);
        $message->setChannel($channel);

        if ($replyToId !== null && !$channel->isTodoList()) {
            $parentMessage = $this->messageRepository->find($replyToId);
            if ($parentMessage !== null && $parentMessage->getChannel()->getId() === $channel->getId()) {
                $message->setParentMessage($parentMessage);
            }
        }

        if ($isPoll) {
            $this->attachPoll($message, $pollQuestion, $pollOptions ?? [], $pollAllowMultiple);
        } else {
            $message->setContent(trim($messageText) === '' ? null : $messageText);

            if ($file !== null) {
                try {
                    $this->fileUploadService->uploadAndAttachToMessage($file, $message);
                    $message->setVirusScanStatus('pending');
                } catch (InvalidArgumentException $e) {
                    return new PublishResult(success: false, channel: $channel, error: $e->getMessage());
                }
            }
        }

        $isDmWithRobot = $channel->getSlug() === 'dm-robot-roquette-' . $currentUser->getSlug();
        $isRobotMentioned = $this->isRobotMentioned($messageText);

        if ($isRobotMentioned && !$isDmWithRobot) {
            if (!$this->consumeLlmToken($currentUser)) {
                return new PublishResult(
                    success: false,
                    channel: $channel,
                    error: $this->translator->trans('Trop de demandes pour l\'Assistant. Veuillez patienter un instant.'),
                    statusCode: Response::HTTP_TOO_MANY_REQUESTS,
                );
            }

            // When querying the robot in a channel, do NOT persist the message in DB nor broadcast it to everyone.
            // Dispatch async LLM processing with the user's question, which will stream privately back to the user.
            $helpMessageId = 'help-' . uniqid();
            $this->messageBus->dispatch(
                new LlmQueryMessage($messageText, $currentUser->getId(), $channel->getSlug(), $helpMessageId, workspaceId: $workspaceId),
            );

            return new PublishResult(success: true, channel: $channel, message: $message, renderedHtml: '');
        }

        $llmAllowed = $isDmWithRobot && !$isPoll && $file === null
            ? $this->consumeLlmToken($currentUser)
            : true;

        if (!$llmAllowed) {
            return new PublishResult(
                success: false,
                channel: $channel,
                error: $this->translator->trans('Trop de demandes pour l\'Assistant. Veuillez patienter un instant.'),
                statusCode: Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        if ($file !== null) {
            $this->messageBus->dispatch(new ScanFileMessage($message->getId()));
        }

        if ($message->getContent() !== null && !$isPoll && !$channel->isDm()) {
            $this->messageBus->dispatch(new ModerateMessageMessage($message->getId()));
        }



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

        if ($isDmWithRobot && !$isPoll && $file === null) {
            $this->messageBus->dispatch(
                new LlmQueryMessage($messageText, $currentUser->getId(), $channel->getSlug(), 'help-' . uniqid(), workspaceId: $workspaceId),
            );
        }

        return new PublishResult(success: true, channel: $channel, message: $message, renderedHtml: $renderedHtml);
    }

    private function isRobotMentioned(string $messageText): bool
    {
        $robot = $this->userRepository->findOneBy(['username' => User::ROBOT_USERNAME]);
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

    private function consumeLlmToken(User $user): bool
    {
        return $this->llmRateLimiter->create('user_' . $user->getId())->consume(1)->isAccepted();
    }

    private function attachPoll(Message $message, string $pollQuestion, array $optionsData, bool $allowMultiple): void
    {
        $poll = new \App\Entity\Poll();
        $poll->setQuestion(trim($pollQuestion));
        $poll->setAllowMultiple($allowMultiple);
        $poll->setMessage($message);
        $message->setPoll($poll);

        $position = 0;
        foreach ($optionsData as $optionText) {
            $option = new \App\Entity\PollOption();
            $option->setText($optionText);
            $option->setPosition($position++);
            $poll->addOption($option);
        }

        $this->entityManager->persist($poll);
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
