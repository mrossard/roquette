<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Message\LlmQueryMessage;
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
        private readonly FileUploadService $fileUploadService,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly MessageRenderer $messageRenderer,
        private readonly Environment $twig,
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
    ): PublishResult {
        $isPoll = $pollQuestion !== null && $pollQuestion !== '';

        if (trim($messageText) === '' && !$file && !$isPoll) {
            return new PublishResult(success: false, channel: $channel);
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
                } catch (\InvalidArgumentException $e) {
                    return new PublishResult(success: false, channel: $channel, error: $e->getMessage());
                }
            }
        }

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        if ($file !== null) {
            $this->messageBus->dispatch(new ScanFileMessage($message->getId()));
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

        if ($channel->getSlug() === 'dm-robot-roquette-' . $currentUser->getSlug() && !$isPoll && $file === null) {
            $this->messageBus->dispatch(
                new LlmQueryMessage($messageText, $currentUser->getId(), $channel->getSlug(), 'help-' . uniqid()),
            );
        }

        return new PublishResult(success: true, channel: $channel, message: $message, renderedHtml: $renderedHtml);
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
