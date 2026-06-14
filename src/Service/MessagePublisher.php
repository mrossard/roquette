<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Message\LlmQueryMessage;
use App\Message\ScanFileMessage;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class MessagePublisher
{
    public function __construct(
        private readonly ChannelRepository $channelRepository,
        private readonly MessageRepository $messageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisher $mercurePublisher,
        private readonly FileUploadService $fileUploadService,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly Environment $twig,
        private readonly SlashCommandHandler $slashCommandHandler,
        private readonly RequestStack $requestStack,
        #[Autowire(service: 'limiter.message_api')]
        private readonly RateLimiterFactoryInterface $rateLimiter,
    ) {}

    public function publish(string $slug, Request $request, User $currentUser): Response
    {
        $channel = $this->findChannel($slug, $currentUser);

        $limiter = $this->rateLimiter->create('user_' . $currentUser->getId());
        if (!$limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', $this->translator->trans('Trop de messages envoyés. Veuillez patienter.'));

            return $this->renderForm($channel, Response::HTTP_TOO_MANY_REQUESTS);
        }

        if ($this->isPostMaxSizeExceeded($request)) {
            $this->addFlash(
                'error',
                $this->translator->trans('Le fichier est trop volumineux pour être envoyé (limite post_max_size dépassée).'),
            );

            return $this->renderForm($channel);
        }

        $messageText = $request->request->get('message', '');
        $uploadedFile = $request->files->get('file');
        $pollQuestion = $request->request->get('poll_question');
        $isPoll = $pollQuestion !== null && $pollQuestion !== '';

        if (trim($messageText) === '' && !$uploadedFile && !$isPoll) {
            return $this->renderForm($channel);
        }

        if ($isPoll) {
            $optionsData = $this->getPollOptions($request);

            if (count($optionsData) < 2) {
                return new Response($this->translator->trans('Un sondage requiert au moins 2 options.'), 400);
            }
        }

        if (!$isPoll && !$uploadedFile && str_starts_with(trim($messageText), '/')) {
            $response = $this->slashCommandHandler->process($messageText, $channel, $currentUser);
            if ($response !== null) {
                return $response;
            }
        }

        $message = new Message();
        $message->setAuthor($currentUser);
        $message->setChannel($channel);

        $replyToId = $request->request->get('replyTo');
        if ($replyToId && !$channel->isTodoList()) {
            $parentMessage = $this->messageRepository->find((int) $replyToId);
            if ($parentMessage && $parentMessage->getChannel()->getId() === $channel->getId()) {
                $message->setParentMessage($parentMessage);
            }
        }

        if ($isPoll) {
            $this->attachPoll($message, $request, $pollQuestion, $optionsData);
        } else {
            $message->setContent(trim($messageText) === '' ? null : $messageText);

            if ($uploadedFile) {
                try {
                    $this->fileUploadService->uploadAndAttachToMessage($uploadedFile, $message);
                    $message->setVirusScanStatus('pending');
                } catch (\InvalidArgumentException $e) {
                    $this->addFlash('error', $e->getMessage());

                    return $this->renderForm($channel);
                }
            }
        }

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        if ($uploadedFile) {
            $this->messageBus->dispatch(new ScanFileMessage($message->getId()));
        }

        $renderedHtml = $this->renderFeedItem($message);

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

        if (
            $channel->getSlug() === 'dm-robot-roquette-' . $currentUser->getSlug()
            && !$isPoll
            && !$uploadedFile
        ) {
            $this->messageBus->dispatch(
                new LlmQueryMessage($messageText, $currentUser->getId(), $channel->getSlug(), 'help-' . uniqid()),
            );
        }

        return $this->renderForm($channel);
    }

    private function findChannel(string $slug, User $currentUser): Channel
    {
        $channel = $this->channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            throw new NotFoundHttpException($this->translator->trans('Canal non trouvé.'));
        }

        if (!$this->channelRepository->canUserAccess($channel, $currentUser)) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé.'));
        }

        return $channel;
    }

    private function renderForm(Channel $channel, int $statusCode = 200): Response
    {
        return new Response(
            $this->twig->render('dashboard/_input_form.html.twig', [
                'activeChannel' => $channel,
            ]),
            $statusCode,
        );
    }

    private function renderFeedItem(Message $message, array $extraParams = []): string
    {
        return $this->twig->render('dashboard/_feed_item.html.twig', array_merge(
            $this->feedItemParams($message),
            $extraParams,
        ));
    }

    private function feedItemParams(Message $message): array
    {
        return [
            'author' => $message->getAuthor(),
            'message' => $message->getContent(),
            'timestamp' => $message->getCreatedAt(),
            'message_id' => $message->getId(),
            'updated_at' => $message->getUpdatedAt(),
            'fileName' => $message->getFileName(),
            'fileSize' => $message->getFileSize(),
            'filePath' => $message->getFilePath(),
            'mimeType' => $message->getMimeType(),
            'messageObject' => $message,
        ];
    }

    private function maybePrependDaySeparator(Message $previousMessage, Message $newMessage, string $renderedHtml): string
    {
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

    private function attachPoll(Message $message, Request $request, string $pollQuestion, array $optionsData): void
    {
        $poll = new \App\Entity\Poll();
        $poll->setQuestion(trim($pollQuestion));
        $poll->setAllowMultiple((bool) $request->request->get('allow_multiple'));
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

    private function isPostMaxSizeExceeded(Request $request): bool
    {
        return (
            $request->isMethod('POST')
            && count($request->request) === 0
            && count($request->files) === 0
            && (int) $request->headers->get('CONTENT_LENGTH', 0) > 0
        );
    }

    private function addFlash(string $type, string $message): void
    {
        $session = $this->requestStack->getSession();
        if ($session !== null && $session->isStarted()) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    /** @return string[] */
    private function getPollOptions(Request $request): array
    {
        $optionsData = $request->request->all()['poll_options'] ?? [];
        if (!is_array($optionsData)) {
            return [];
        }

        return array_filter(array_map('trim', $optionsData), static fn($val) => $val !== '');
    }
}
