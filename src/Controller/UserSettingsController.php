<?php

namespace App\Controller;


use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class UserSettingsController extends AbstractController
{
    // -------------------------------------------------------------------------
    // Update avatar color
    // -------------------------------------------------------------------------

    #[Route('/user/update-color', name: 'app_user_update_color', methods: ['POST'])]
    public function updateColor(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $hue = $request->request->get('hue');
        if ($hue === null) {
            return new Response('Teinte manquante.', 400);
        }

        $hueVal = (int) $hue;
        if ($hueVal < 0 || $hueVal > 360) {
            return new Response('Teinte invalide.', 400);
        }

        $currentUser->setCustomHue($hueVal);
        $entityManager->flush();

        return new Response(null, 204, ['HX-Refresh' => 'true']);
    }

    // -------------------------------------------------------------------------
    // Update interface theme (dark / light)
    // -------------------------------------------------------------------------

    #[Route('/user/update-theme', name: 'app_user_update_theme', methods: ['POST'])]
    public function updateTheme(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $theme = $request->request->get('theme');
        if (!in_array($theme, ['light', 'dark'], true)) {
            return new Response('Thème invalide.', 400);
        }

        $currentUser->setTheme($theme);
        $entityManager->flush();

        return new Response(null, 204);
    }

    // -------------------------------------------------------------------------
    // Update presence status
    // -------------------------------------------------------------------------

    #[Route('/user/update-status', name: 'app_user_update_status', methods: ['POST'])]
    public function updateStatus(
        Request $request,
        EntityManagerInterface $entityManager,
        MercurePublisher $mercurePublisher
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $status = $request->request->get('status');
        if (in_array($status, ['auto', 'online', 'away', 'busy', 'offline'], true)) {
            $currentUser->setStatusOverride($status === 'auto' ? null : $status);
            $entityManager->flush();

            $mercurePublisher->publishToTopic($mercurePublisher->getStatusTopic(), [
                'type'           => 'user_status_changed',
                'username'       => $currentUser->getUsername(),
                'status'         => $currentUser->getStatus(),
                'statusLabel'    => $currentUser->getStatusLabel(),
                'statusOverride' => $currentUser->getStatusOverride() ?? 'auto',
                'lastActive'     => $currentUser->getLastActiveAt()
                    ? $currentUser->getLastActiveAt()->getTimestamp()
                    : null,
            ]);

            return new Response(null, 204);
        }

        return new Response('Statut invalide.', 400);
    }

    // -------------------------------------------------------------------------
    // Activity ping
    // -------------------------------------------------------------------------

    #[Route('/user/ping', name: 'app_user_ping', methods: ['GET', 'POST'])]
    public function ping(): Response
    {
        return new Response(null, 204);
    }

    // -------------------------------------------------------------------------
    // API: list users
    // -------------------------------------------------------------------------

    #[Route('/api/users', name: 'app_api_users', methods: ['GET'])]
    public function apiUsers(EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $users = $entityManager->getRepository(\App\Entity\User::class)->findAll();
        $data  = [];
        foreach ($users as $user) {
            $data[] = [
                'id'          => $user->getId(),
                'username'    => $user->getUsername(),
                'displayName' => $user->getDisplayName(),
                'hue'         => $user->getHue(),
            ];
        }

        return new JsonResponse($data);
    }

    // -------------------------------------------------------------------------
    // Pin/Unpin message (moved from DashboardController, logically user/channel action)
    // -------------------------------------------------------------------------

    #[Route('/messages/{id}/pin', name: 'app_message_pin', methods: ['POST'])]
    public function pinMessage(
        int $id,
        \App\Repository\MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        MercurePublisher $mercurePublisher
    ): Response {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response('Message non trouvé.', 404);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channel = $message->getChannel();
        if ($channel->getCreator() !== $currentUser) {
            return new Response('Seul le créateur du canal peut épingler un message.', 403);
        }

        $previousPinnedMessage = $channel->getPinnedMessage();
        $channel->setPinnedMessage($message);
        $entityManager->flush();

        $bannerHtml  = $this->renderView('dashboard/_pinned_banner.html.twig', [
            'pinnedMessage' => $message,
            'activeChannel' => $channel,
        ]);
        $messageHtml = $this->renderMessageItem($message);

        $previousMessageHtml = null;
        if ($previousPinnedMessage) {
            $previousMessageHtml = $this->renderMessageItem($previousPinnedMessage);
        }

        $mercurePublisher->publishToChannel($channel, [
            'type'                => 'pin_change',
            'channelSlug'         => $channel->getSlug(),
            'bannerHtml'          => $bannerHtml,
            'messageId'           => $message->getId(),
            'messageHtml'         => $messageHtml,
            'previousMessageId'   => $previousPinnedMessage ? $previousPinnedMessage->getId() : null,
            'previousMessageHtml' => $previousMessageHtml,
        ]);

        return new Response($bannerHtml);
    }

    #[Route('/messages/{id}/unpin', name: 'app_message_unpin', methods: ['POST'])]
    public function unpinMessage(
        int $id,
        \App\Repository\MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        MercurePublisher $mercurePublisher
    ): Response {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response('Message non trouvé.', 404);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channel = $message->getChannel();
        if ($channel->getCreator() !== $currentUser) {
            return new Response('Seul le créateur du canal peut désépingler un message.', 403);
        }

        if ($channel->getPinnedMessage() === $message) {
            $channel->setPinnedMessage(null);
            $entityManager->flush();

            $messageHtml = $this->renderMessageItem($message);

            $mercurePublisher->publishToChannel($channel, [
                'type'        => 'pin_change',
                'channelSlug' => $channel->getSlug(),
                'bannerHtml'  => '',
                'messageId'   => $message->getId(),
                'messageHtml' => $messageHtml,
            ]);
        }

        return new Response('');
    }

    // -------------------------------------------------------------------------
    // Private helper
    // -------------------------------------------------------------------------

    private function renderMessageItem(\App\Entity\Message $message): string
    {
        return $this->renderView('dashboard/_feed_item.html.twig', [
            'author'        => $message->getAuthor(),
            'message'       => $message->getContent(),
            'timestamp'     => $message->getCreatedAt(),
            'message_id'    => $message->getId(),
            'updated_at'    => $message->getUpdatedAt(),
            'fileName'      => $message->getFileName(),
            'fileSize'      => $message->getFileSize(),
            'filePath'      => $message->getFilePath(),
            'mimeType'      => $message->getMimeType(),
            'messageObject' => $message,
        ]);
    }
}
