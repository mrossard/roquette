<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Dto\Message\EditMessageDto;
use App\Repository\MessageRepository;
use App\Service\MessageDeletionService;
use App\Service\MessageEditor;
use App\Service\MessageFeedContextService;
use App\Service\MessageFormatter;
use App\Service\MessageRenderer;
use App\Service\MessageSubmissionHandler;
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
    use ChannelAccessTrait;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly MessageRenderer $messageRenderer,
        private readonly MessageFeedContextService $feedContextService,
    ) {}

    #[Route('/api/message/preview', name: 'app_api_message_preview', methods: ['POST'])]
    public function preview(
        Request $request,
        SlashCommandHandler $slashCommandHandler,
        MessageFormatter $messageFormatter,
    ): Response {
        $content = '';
        $rawBody = $request->getContent();
        if ($rawBody !== '') {
            $data = json_decode($rawBody, true);
            if (is_array($data)) {
                $content = (string) ($data['content'] ?? '');
            }
        }

        if ($content === '') {
            $requestContent = $request->request->get('content');
            $content = $requestContent !== null && (string) $requestContent !== ''
                ? (string) $requestContent
                : (string) $request->request->get('message', '');
        }

        $content = $slashCommandHandler->processPreview($content);

        $html = $messageFormatter->format($content);

        return new Response(
            $html !== ''
                ? $html
                : '<span class="preview-empty">' . $this->translator->trans('Rien à prévisualiser') . '</span>',
        );
    }

    #[Route('/channels/{slug}/publish', name: 'app_publish', methods: ['POST'])]
    public function publish(string $slug, Request $request, MessageSubmissionHandler $submissionHandler): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        return $submissionHandler->handle($slug, $request, $currentUser);
    }

    #[Route('/messages/{id}/edit', name: 'app_message_edit_form', methods: ['GET'])]
    public function editMessageForm(
        int $id,
        MessageRepository $messageRepository,
        MessageEditor $messageEditor,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        try {
            $message = $messageEditor->getEditableMessage($id, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $message = $messageRepository->find($id);
            if ($message !== null) {
                return $this->render('dashboard/_feed_item.html.twig', array_merge(
                    $this->messageRenderer->feedItemParams($message),
                    ['no_fade' => true],
                ), new Response(status: $e->getStatusCode()));
            }

            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return $this->render('dashboard/_edit_form.html.twig', ['message' => $message]);
    }

    #[Route('/messages/{id}/edit', name: 'app_message_edit', methods: ['POST'])]
    public function editMessage(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        MessageEditor $messageEditor,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $dto = EditMessageDto::fromRequest($request);

        try {
            $renderedHtml = $messageEditor->edit($id, $currentUser, $dto);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $message = $messageRepository->find($id);
            if ($message !== null) {
                return $this->render('dashboard/_edit_form.html.twig', [
                    'message' => $message,
                    'error' => $e->getMessage(),
                ], new Response(status: $e->getStatusCode()));
            }

            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return new Response($renderedHtml);
    }

    #[Route('/messages/{id}/delete', name: 'app_message_delete', methods: ['POST'])]
    public function deleteMessage(int $id, MessageDeletionService $messageDeletionService): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        try {
            $messageDeletionService->delete($id, $currentUser);
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

        $this->authorizeMessageAccess($message);

        return $this->render('dashboard/_feed_item.html.twig', $this->messageRenderer->feedItemParams($message));
    }

    #[Route('/messages/{id}/replies', name: 'app_message_replies', methods: ['GET'])]
    public function replies(
        int $id,
        MessageRepository $messageRepository,
    ): Response {
        $message = $messageRepository->find($id);
        if (!$message) {
            throw $this->createNotFoundException($this->translator->trans('Message non trouvé.'));
        }

        $channel = $message->getChannel();
        if (!$channel) {
            throw $this->createNotFoundException($this->translator->trans('Canal non trouvé.'));
        }

        $this->authorizeMessageAccess($message);

        $replies = $messageRepository->findReplyTree($message);

        // Include the original message as the first item
        $messages = array_merge([$message], $replies);
        $feedContext = $this->feedContextService->buildFeedContext($channel, $messages);

        return $this->render('dashboard/_messages_feed.html.twig', array_merge([
            'messages' => $messages,
            'activeChannel' => $channel,
            'firstUnreadMessageId' => null,
            'threadOf' => $message,
        ], $feedContext));
    }
}
