<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\MessageRendererTrait;
use App\Entity\Message;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Service\FileUploadService;
use App\Service\MercurePublisher;
use App\Service\MessageFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[IsGranted('ROLE_USER')]
final class MessageController extends AbstractController
{
    use MessageRendererTrait;

    public function __construct(
        #[\SensitiveParameter]
        #[Autowire(env: 'TENOR_API_KEY')]
        private string $tenorApiKey,
        private HttpClientInterface $httpClient,
    ) {}

    #[Route('/api/message/preview', name: 'app_api_message_preview', methods: ['POST'])]
    public function preview(Request $request, MessageFormatter $messageFormatter): Response
    {
        $content = '';
        if ($request->getContent()) {
            $data = json_decode($request->getContent(), true);
            $content = $data['content'] ?? '';
        }
        if (!$content) {
            $content = $request->request->get('content') ?: $request->request->get('message', '');
        }

        if (str_starts_with(trim($content), '/shrug')) {
            $parts = explode(' ', trim($content), 2);
            $args = (($parts[1] ?? null) !== null) ? trim($parts[1]) : '';
            $content = ($args !== '' ? $args . ' ' : '') . '¯\_(ツ)_/¯';
        } elseif (str_starts_with(trim($content), '/me ')) {
            $parts = explode(' ', trim($content), 2);
            $args = (($parts[1] ?? null) !== null) ? trim($parts[1]) : '';
            $content = '*' . $args . '*';
        } elseif (trim($content) === '/me') {
            $content = '';
        }

        $html = $messageFormatter->format($content);

        return new Response($html ?: '<span class="preview-empty">Rien à prévisualiser</span>');
    }

    // -------------------------------------------------------------------------
    // Publish (send message)
    // -------------------------------------------------------------------------

    #[Route('/channels/{slug}/publish', name: 'app_publish', methods: ['POST'])]
    public function publish(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
        MercurePublisher $mercurePublisher,
        FileUploadService $fileUploadService,
        RateLimiterFactoryInterface $messageApiLimiter,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $activeChannel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$activeChannel) {
            return new Response('Canal non trouvé.', 404);
        }

        $limiter = $messageApiLimiter->create('user_' . $currentUser->getId());
        if (false === $limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', 'Trop de messages envoyés. Veuillez patienter.');
            return $this->render(
                'dashboard/_input_form.html.twig',
                [
                    'activeChannel' => $activeChannel,
                ],
                new Response('', Response::HTTP_TOO_MANY_REQUESTS),
            );
        }

        if ($activeChannel->isPrivate() && !$activeChannel->getMembers()->contains($currentUser)) {
            return new Response('Non autorisé.', 403);
        }

        if (
            $request->isMethod('POST')
            && count($request->request) === 0
            && count($request->files) === 0
            && (int) $request->headers->get('CONTENT_LENGTH', 0) > 0
        ) {
            $this->addFlash(
                'error',
                'Le fichier est trop volumineux pour être envoyé (limite post_max_size dépassée).',
            );
            return $this->render('dashboard/_input_form.html.twig', [
                'activeChannel' => $activeChannel,
            ]);
        }

        $messageText = $request->request->get('message', '');
        $uploadedFile = $request->files->get('file');
        $pollQuestion = $request->request->get('poll_question');
        $isPoll = !empty($pollQuestion);

        if (trim($messageText) === '' && !$uploadedFile && !$isPoll) {
            return $this->render('dashboard/_input_form.html.twig', [
                'activeChannel' => $activeChannel,
            ]);
        }

        if ($isPoll) {
            $optionsData = $this->getPollOptions($request);

            if (count($optionsData) < 2) {
                return new Response('Un sondage requiert au moins 2 options.', 400);
            }
        }

        // Slash commands (only when no file and not a poll)
        if (!$isPoll && !$uploadedFile && str_starts_with(trim($messageText), '/')) {
            $response = $this->handleSlashCommand($messageText, $activeChannel, $currentUser, $entityManager);
            if ($response !== null) {
                return $response;
            }

            // $messageText may have been mutated by the slash command (e.g. /shrug)
        }

        $message = new Message();
        $message->setAuthor($currentUser);
        $message->setChannel($activeChannel);

        if ($isPoll) {
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
            $entityManager->persist($poll);
        } else {
            $message->setContent(trim($messageText) === '' ? null : $messageText);

            if ($uploadedFile) {
                try {
                    $meta = $fileUploadService->upload($uploadedFile);
                    $message->setFileName($meta['fileName']);
                    $message->setFilePath($meta['filePath']);
                    $message->setFileSize($meta['fileSize']);
                    $message->setMimeType($meta['mimeType']);
                } catch (\InvalidArgumentException $e) {
                    $this->addFlash('error', $e->getMessage());
                    return $this->render('dashboard/_input_form.html.twig', [
                        'activeChannel' => $activeChannel,
                    ]);
                }
            }
        }

        $entityManager->persist($message);
        $entityManager->flush();

        $renderedHtml = $this->renderFeedItem($message);

        $mercurePublisher->publishNewMessage(
            $activeChannel,
            $message,
            $currentUser,
            $isPoll ? 'Sondage : ' . $poll->getQuestion() : $messageText,
            $renderedHtml,
            $entityManager,
        );

        return $this->render('dashboard/_input_form.html.twig', [
            'activeChannel' => $activeChannel,
        ]);
    }

    // -------------------------------------------------------------------------
    // Edit message
    // -------------------------------------------------------------------------

    #[Route('/messages/{id}/edit', name: 'app_message_edit_form', methods: ['GET'])]
    public function editMessageForm(int $id, MessageRepository $messageRepository): Response
    {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response('Message non trouvé.', 404);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        if ($message->getAuthor() !== $currentUser) {
            return new Response('Non autorisé à modifier ce message.', 403);
        }

        if ($message->isPoll() && $message->getPoll()->getTotalVotes() > 0) {
            return new Response('Impossible de modifier un sondage qui a déjà des votes.', 400);
        }

        return $this->render('dashboard/_edit_form.html.twig', [
            'message' => $message,
        ]);
    }

    #[Route('/messages/{id}/edit', name: 'app_message_edit', methods: ['POST'])]
    public function editMessage(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        MercurePublisher $mercurePublisher,
    ): Response {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response('Message non trouvé.', 404);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        if ($message->getAuthor() !== $currentUser) {
            return new Response('Non autorisé à modifier ce message.', 403);
        }

        if ($message->isPoll()) {
            if ($message->getPoll()->getTotalVotes() > 0) {
                return new Response('Impossible de modifier un sondage qui a déjà des votes.', 400);
            }
            $pollQuestion = $request->request->get('poll_question');
            $optionsData = $this->getPollOptions($request);

            if (empty($pollQuestion)) {
                return new Response('La question du sondage ne peut pas être vide.', 400);
            }
            if (count($optionsData) < 2) {
                return new Response('Un sondage requiert au moins 2 options.', 400);
            }

            $poll = $message->getPoll();
            $poll->setQuestion(trim($pollQuestion));
            $poll->setAllowMultiple((bool) $request->request->get('allow_multiple'));

            $existingOptions = $poll->getOptions()->getValues();
            $position = 0;
            foreach ($optionsData as $idx => $optText) {
                if (isset($existingOptions[$idx])) {
                    if ($existingOptions[$idx]->getText() !== $optText) {
                        $existingOptions[$idx]->setText($optText);
                        $existingOptions[$idx]->getVotes()->clear();
                    }
                    $existingOptions[$idx]->setPosition($position++);
                } else {
                    $newOption = new \App\Entity\PollOption();
                    $newOption->setText($optText);
                    $newOption->setPosition($position++);
                    $poll->addOption($newOption);
                }
            }

            // Remove extra options if the new count is less than existing count
            for ($i = count($optionsData); $i < count($existingOptions); $i++) {
                $poll->removeOption($existingOptions[$i]);
            }
            
            $message->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();
        } else {
            $newContent = $request->request->get('content', '');
            if (trim($newContent) === '' && !$message->getFilePath()) {
                return new Response('Le message ne peut pas être vide.', 400);
            }

            $message->setContent(trim($newContent) === '' ? null : $newContent);
            $message->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();
        }

        $renderedHtml = $this->renderFeedItem($message);

        $channel = $message->getChannel();
        $mercurePublisher->publishToChannel($channel, [
            'html' => $renderedHtml,
            'user' => $currentUser->getUsername(),
            'channelSlug' => $channel->getSlug(),
        ]);

        return new Response($renderedHtml);
    }

    // -------------------------------------------------------------------------
    // Delete message
    // -------------------------------------------------------------------------

    #[Route('/messages/{id}/delete', name: 'app_message_delete', methods: ['POST'])]
    public function deleteMessage(
        int $id,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        MercurePublisher $mercurePublisher,
        FileUploadService $fileUploadService,
    ): Response {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response('Message non trouvé.', 404);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channel = $message->getChannel();

        if ($message->getAuthor() !== $currentUser && $channel->getCreator() !== $currentUser) {
            return new Response('Non autorisé à supprimer ce message.', 403);
        }

        if ($channel->getPinnedMessage() === $message) {
            $channel->setPinnedMessage(null);
            $mercurePublisher->publishToChannel($channel, [
                'type' => 'pin_change',
                'channelSlug' => $channel->getSlug(),
                'bannerHtml' => '',
            ]);
        }

        if ($message->getFilePath()) {
            $fileUploadService->delete($message->getFilePath());
        }

        $entityManager->remove($message);
        $entityManager->flush();

        $mercurePublisher->publishToChannel($channel, [
            'type' => 'message_deleted',
            'messageId' => $id,
            'channelSlug' => $channel->getSlug(),
        ]);

        return new Response('', 204);
    }

    // -------------------------------------------------------------------------
    // View message
    // -------------------------------------------------------------------------

    #[Route('/messages/{id}', name: 'app_message_view', methods: ['GET'])]
    public function viewMessage(int $id, MessageRepository $messageRepository): Response
    {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response('Message non trouvé.', 404);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channel = $message->getChannel();
        if (($channel->isPrivate() || $channel->isDm()) && !$channel->getMembers()->contains($currentUser)) {
            return new Response('Non autorisé.', 403);
        }

        return $this->render('dashboard/_feed_item.html.twig', $this->feedItemParams($message));
    }

    // -------------------------------------------------------------------------
    // Save / unsave message
    // -------------------------------------------------------------------------

    #[Route('/messages/{id}/save', name: 'app_message_save_toggle', methods: ['POST'])]
    public function toggleSaveMessage(
        int $id,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response('Message non trouvé.', 404);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        if ($currentUser->getSavedMessages()->contains($message)) {
            $currentUser->removeSavedMessage($message);
        } else {
            $currentUser->addSavedMessage($message);
        }
        $entityManager->flush();

        return $this->render('dashboard/_feed_item.html.twig', $this->feedItemParams($message));
    }

    // -------------------------------------------------------------------------
    // Saved messages page
    // -------------------------------------------------------------------------

    #[Route('/saved-messages', name: 'app_saved_messages', methods: ['GET'])]
    public function savedMessages(ChannelRepository $channelRepository): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channels = $channelRepository->findAllForUser($currentUser);
        $savedMessages = $currentUser->getSavedMessages();

        return $this->render('dashboard/saved_messages.html.twig', [
            'channels' => $channels,
            'savedMessages' => $savedMessages,
            'activeChannel' => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Slash command handler (private)
    // -------------------------------------------------------------------------

    private function handleSlashCommand(
        string &$messageText,
        \App\Entity\Channel $activeChannel,
        \App\Entity\User $currentUser,
        EntityManagerInterface $entityManager,
    ): ?Response {
        $trimmedMsg = trim($messageText);
        $parts = explode(' ', $trimmedMsg, 2);
        $command = strtolower(substr($parts[0], 1));
        $args = (($parts[1] ?? null) !== null) ? trim($parts[1]) : '';

        if ($command === 'color') {
            $hueVal = $args !== '' && is_numeric($args) ? (int) $args : rand(0, 360);
            if ($hueVal >= 0 && $hueVal <= 360) {
                $currentUser->setCustomHue($hueVal);
                $entityManager->flush();

                return $this->render(
                    'dashboard/_input_form.html.twig',
                    [
                        'activeChannel' => $activeChannel,
                    ],
                    new Response('', 200, ['HX-Refresh' => 'true']),
                );
            }
        } elseif ($command === 'shrug') {
            // Mutate messageText so the caller sends the formatted shrug text
            $messageText = ($args !== '' ? $args . ' ' : '') . '¯\_(ツ)_/¯';
            return null; // let the message be sent normally
        } elseif ($command === 'me') {
            $messageText = '/me' . ($args !== '' ? ' ' . $args : '');
            return null; // let the message be sent normally
        } elseif ($command === 'giphy') {
            if ($args === '') {
                $args = 'funny';
            }

            $giphyPreviews = [];
            try {
                $url =
                    'https://g.tenor.com/v1/search?q=' . urlencode($args) . '&key=' . $this->tenorApiKey . '&limit=6';
                $response = $this->httpClient->request('GET', $url, ['timeout' => 3]);
                $data = $response->toArray();
                if (($data['results'] ?? null) !== null && $data['results'] !== []) {
                    foreach ($data['results'] as $result) {
                        if (($result['media'][0]['gif']['url'] ?? null) === null || $result['media'][0]['gif']['url'] === '') {
                            continue;
                        }

                        $giphyPreviews[] = [
                            'url' => $result['media'][0]['gif']['url'],
                            'preview' =>
                                $result['media'][0]['tinygif']['url'] ?? $result['media'][0]['gif']['preview']
                                    ?? $result['media'][0]['gif']['url'],
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Suppress linter warning by performing a safe fallback assignment
                $giphyPreviews = [];
            }

            return $this->render('dashboard/_input_form.html.twig', [
                'activeChannel' => $activeChannel,
                'giphyPreviews' => $giphyPreviews,
                'giphyQuery' => $args,
            ]);
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function getPollOptions(Request $request): array
    {
        $optionsData = $request->request->all()['poll_options'] ?? [];
        if (!is_array($optionsData)) {
            return [];
        }

        return array_filter(array_map('trim', $optionsData), fn($val) => $val !== '');
    }
}
