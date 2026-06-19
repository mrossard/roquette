<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\MessageRendererTrait;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Repository\ReactionRepository;
use App\Service\MessageFormatter;
use App\Service\MessageManager;
use App\Service\MessagePublisher;
use App\Service\SlashCommandHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class MessageController extends AbstractController
{
    use MessageRendererTrait;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/api/message/preview', name: 'app_api_message_preview', methods: ['POST'])]
    public function preview(
        Request $request,
        SlashCommandHandler $slashCommandHandler,
        MessageFormatter $messageFormatter,
    ): Response {
        $content = '';
        if ($request->getContent()) {
            $data = json_decode($request->getContent(), true);
            $content = $data['content'] ?? '';
        }
        if (!$content) {
            $requestContent = $request->request->get('content');
            $content = ($requestContent !== null && $requestContent !== '') ? $requestContent : $request->request->get('message', '');
        }

        $content = $slashCommandHandler->processPreview($content);

        $html = $messageFormatter->format($content);

        return new Response(
            ($html !== '') ? $html : '<span class="preview-empty">' . $this->translator->trans('Rien à prévisualiser') . '</span>',
        );
    }

    #[Route('/channels/{slug}/publish', name: 'app_publish', methods: ['POST'])]
    public function publish(
        string $slug,
        Request $request,
        MessagePublisher $messagePublisher,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        return $messagePublisher->publish($slug, $request, $currentUser);
    }

    #[Route('/messages/{id}/edit', name: 'app_message_edit_form', methods: ['GET'])]
    public function editMessageForm(int $id, MessageManager $messageManager): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        try {
            $result = $messageManager->editMessageForm($id, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return $this->render('dashboard/_edit_form.html.twig', $result);
    }

    #[Route('/messages/{id}/edit', name: 'app_message_edit', methods: ['POST'])]
    public function editMessage(int $id, Request $request, MessageManager $messageManager): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        try {
            $result = $messageManager->editMessage($id, $request, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        if (array_key_exists('error', $result)) {
            return new Response($result['error'], $result['statusCode'] ?? 400);
        }

        return new Response($result['renderedHtml']);
    }

    #[Route('/messages/{id}/delete', name: 'app_message_delete', methods: ['POST'])]
    public function deleteMessage(int $id, MessageManager $messageManager): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        try {
            $messageManager->deleteMessage($id, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return new Response('', 204);
    }

    #[Route('/messages/{id}', name: 'app_message_view', methods: ['GET'])]
    public function viewMessage(int $id, MessageRepository $messageRepository): Response
    {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channel = $message->getChannel();
        if (($channel->isPrivate() || $channel->isDm()) && !$channel->getMembers()->contains($currentUser)) {
            return new Response($this->translator->trans('Non autorisé.'), 403);
        }

        return $this->render('dashboard/_feed_item.html.twig', $this->feedItemParams($message));
    }

    #[Route('/messages/{id}/save', name: 'app_message_save_toggle', methods: ['POST'])]
    public function toggleSaveMessage(int $id, MessageManager $messageManager): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        try {
            $message = $messageManager->toggleSaveMessage($id, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return $this->render('dashboard/_feed_item.html.twig', $this->feedItemParams($message));
    }

    #[Route('/saved-messages', name: 'app_saved_messages', methods: ['GET'])]
    #[Route('/saved-messages/more', name: 'app_saved_messages_more', methods: ['GET'])]
    public function savedMessages(
        Request $request,
        ChannelRepository $channelRepository,
        MessageRepository $messageRepository,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $beforeId = $request->query->getInt('beforeId');
        $beforeId = $beforeId > 0 ? $beforeId : null;

        $savedMessages = $messageRepository->findSavedByUser($currentUser, 50, $beforeId);
        $hasMore = count($savedMessages) === 50;
        $nextBeforeId = $hasMore ? $savedMessages[array_key_last($savedMessages)]->getId() : null;

        if ($beforeId !== null) {
            return $this->render('dashboard/_more_saved_messages.html.twig', [
                'savedMessages' => $savedMessages,
                'hasMore' => $hasMore,
                'nextBeforeId' => $nextBeforeId,
            ]);
        }

        $channels = $channelRepository->findAllForUser($currentUser);

        return $this->render('dashboard/saved_messages.html.twig', [
            'channels' => $channels,
            'savedMessages' => $savedMessages,
            'hasMore' => $hasMore,
            'nextBeforeId' => $nextBeforeId,
            'activeChannel' => null,
        ]);
    }

    #[Route('/my-reactions', name: 'app_my_reactions', methods: ['GET'])]
    #[Route('/my-reactions/{emoji}', name: 'app_my_reactions_filtered', methods: ['GET'])]
    public function myReactions(
        Request $request,
        ReactionRepository $reactionRepository,
        ChannelRepository $channelRepository,
        ?string $emoji = null,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $beforeId = $request->query->getInt('beforeId');
        $beforeId = $beforeId > 0 ? $beforeId : null;

        if ($beforeId !== null) {
            $messages = $emoji
                ? $reactionRepository->findDistinctMessagesByUserAndEmoji($currentUser, $emoji, 50, $beforeId)
                : $reactionRepository->findDistinctMessagesByUser($currentUser, 50, $beforeId);
            $hasMore = count($messages) === 50;
            $nextBeforeId = $hasMore ? $messages[array_key_last($messages)]->getId() : null;

            return $this->render('dashboard/_more_my_reactions.html.twig', [
                'reactedMessages' => $messages,
                'hasMore' => $hasMore,
                'nextBeforeId' => $nextBeforeId,
                'activeEmoji' => $emoji,
            ]);
        }

        $channels = $channelRepository->findAllForUser($currentUser);
        $messages = $emoji
            ? $reactionRepository->findDistinctMessagesByUserAndEmoji($currentUser, $emoji, 50)
            : $reactionRepository->findDistinctMessagesByUser($currentUser, 50);
        $userEmojis = $reactionRepository->findUserEmojis($currentUser);
        $hasMore = count($messages) === 50;
        $nextBeforeId = $hasMore ? $messages[array_key_last($messages)]->getId() : null;

        return $this->render('dashboard/my_reactions.html.twig', [
            'channels' => $channels,
            'reactedMessages' => $messages,
            'userEmojis' => $userEmojis,
            'activeEmoji' => $emoji,
            'activeChannel' => null,
            'hasMore' => $hasMore,
            'nextBeforeId' => $nextBeforeId,
        ]);
    }
}
