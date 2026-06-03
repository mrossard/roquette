<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\MessageRendererTrait;
use App\Controller\Trait\RequestValidationTrait;
use App\Entity\Message;
use App\Entity\UserChannelRead;
use App\Repository\MessageRepository;
use App\Service\FileUploadService;
use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ThreadController extends AbstractController
{
    use MessageRendererTrait;
    use RequestValidationTrait;

    // -------------------------------------------------------------------------
    // View thread
    // -------------------------------------------------------------------------

    #[Route('/messages/{id}/thread', name: 'app_message_thread', methods: ['GET'])]
    public function viewThread(int $id, MessageRepository $messageRepository): Response
    {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response('Message non trouvé.', 404);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channel = $message->getChannel();
        if ($channel->isPrivate() && !$channel->getMembers()->contains($currentUser)) {
            return new Response('Non autorisé.', 403);
        }

        return $this->render('dashboard/_thread_pane.html.twig', [
            'parentMessage' => $message,
            'replies' => $message->getReplies(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Mark thread as read
    // -------------------------------------------------------------------------

    #[Route('/messages/{id}/thread/mark-read', name: 'app_thread_mark_read', methods: ['POST'])]
    public function markThreadAsRead(
        int $id,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $parentMessage = $messageRepository->find($id);
        if (!$parentMessage) {
            return new Response('Message non trouvé.', 404);
        }

        $channel = $parentMessage->getChannel();
        if ($channel->isPrivate() && !$channel->getMembers()->contains($currentUser)) {
            return new Response('Non autorisé.', 403);
        }

        $replies = $parentMessage->getReplies()->toArray();
        if ($replies !== []) {
            usort($replies, static fn($a, $b) => $a->getId() <=> $b->getId());
            $latestThreadMessage = end($replies);
        } else {
            $latestThreadMessage = $parentMessage;
        }

        $ucrRepo = $entityManager->getRepository(UserChannelRead::class);
        $activeRead = $ucrRepo->findOneBy(['user' => $currentUser, 'channel' => $channel]);

        $currentLastReadId = $activeRead?->getLastReadMessage()?->getId() ?? 0;
        if ($latestThreadMessage->getId() > $currentLastReadId) {
            if ($activeRead) {
                $activeRead->setLastReadMessage($latestThreadMessage);
            } else {
                $activeRead = new UserChannelRead();
                $activeRead->setUser($currentUser);
                $activeRead->setChannel($channel);
                $activeRead->setLastReadMessage($latestThreadMessage);
                $entityManager->persist($activeRead);
            }
            $entityManager->flush();
        }

        return new Response('', 204);
    }

    // -------------------------------------------------------------------------
    // Post reply
    // -------------------------------------------------------------------------

    #[Route('/messages/{id}/thread/reply', name: 'app_message_thread_reply', methods: ['POST'])]
    public function postReply(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        MercurePublisher $mercurePublisher,
        FileUploadService $fileUploadService,
        RateLimiterFactoryInterface $messageApiLimiter,
    ): Response {
        $parentMessage = $messageRepository->find($id);
        if (!$parentMessage) {
            return new Response('Message parent non trouvé.', 404);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $limiter = $messageApiLimiter->create('user_' . $currentUser->getId());
        if (false === $limiter->consume(1)->isAccepted()) {
            return new Response('Trop de messages envoyés. Veuillez patienter.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $activeChannel = $parentMessage->getChannel();
        if ($activeChannel->isPrivate() && !$activeChannel->getMembers()->contains($currentUser)) {
            return new Response('Non autorisé.', 403);
        }

        if ($this->isPostMaxSizeExceeded($request)) {
            $this->addFlash(
                'error',
                'Le fichier est trop volumineux pour être envoyé (limite post_max_size dépassée).',
            );
            return $this->render('dashboard/_thread_input_form.html.twig', [
                'parentMessage' => $parentMessage,
            ]);
        }

        $messageText = $request->request->get('message', '');
        $uploadedFile = $request->files->get('file');

        if (trim($messageText) === '' && !$uploadedFile) {
            return new Response('Le message ne peut pas être vide.', 400);
        }

        $message = new Message();
        $message->setContent(trim($messageText) === '' ? null : $messageText);
        $message->setAuthor($currentUser);
        $message->setChannel($activeChannel);
        $message->setParent($parentMessage);

        if ($uploadedFile) {
            try {
                $fileUploadService->uploadAndAttachToMessage($uploadedFile, $message);
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
                return $this->render('dashboard/_thread_input_form.html.twig', [
                    'parentMessage' => $parentMessage,
                ]);
            }
        }

        $entityManager->persist($message);
        $entityManager->flush();

        $renderedHtml = $this->renderFeedItem($message);

        $mercurePublisher->publishNewMessage(
            $activeChannel,
            $message,
            $currentUser,
            $messageText,
            $renderedHtml,
            $entityManager,
            $parentMessage->getId(),
            $parentMessage->getReplies()->count(),
            '(Fil) ',
        );

        return $this->render('dashboard/_thread_reply_response.html.twig', [
            'parentMessage' => $parentMessage,
            'replies' => $parentMessage->getReplies(),
        ]);
    }
}
