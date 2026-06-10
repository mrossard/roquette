<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class UserSettingsController extends AbstractController
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {}

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
            return new Response($this->translator->trans('Teinte manquante.'), 400);
        }

        $hueVal = (int) $hue;
        if ($hueVal < 0 || $hueVal > 360) {
            return new Response($this->translator->trans('Teinte invalide.'), 400);
        }

        $currentUser->setCustomHue($hueVal);
        $entityManager->flush();

        return new Response(null, 204, ['HX-Refresh' => 'true']);
    }

    // -------------------------------------------------------------------------
    // Update interface theme (dark / light)
    // -------------------------------------------------------------------------

    #[Route('/user/update-theme', name: 'app_user_update_theme', methods: ['POST'])]
    public function updateTheme(EntityManagerInterface $entityManager): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $newTheme = $currentUser->getTheme() === 'dark' ? 'light' : 'dark';
        $currentUser->setTheme($newTheme);
        $entityManager->flush();

        return new Response(null, 204, ['HX-Refresh' => 'true']);
    }

    // -------------------------------------------------------------------------
    // Update presence status
    // -------------------------------------------------------------------------

    #[Route('/user/update-status', name: 'app_user_update_status', methods: ['POST'])]
    public function updateStatus(
        Request $request,
        EntityManagerInterface $entityManager,
        MercurePublisher $mercurePublisher,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $status = $request->request->get('status');
        if (in_array($status, ['auto', 'online', 'away', 'busy', 'offline'], true)) {
            $currentUser->setStatusOverride($status === 'auto' ? null : $status);
            $entityManager->flush();

            $mercurePublisher->publishToTopic(
                $mercurePublisher->getStatusTopic(),
                [
                    'type' => 'user_status_changed',
                    'username' => $currentUser->getUsername(),
                    'status' => $currentUser->getStatus(),
                    'statusLabel' => $currentUser->getStatusLabel(),
                    'statusOverride' => $currentUser->getStatusOverride() ?? 'auto',
                    'lastActive' => $currentUser->getLastActiveAt()
                        ? $currentUser->getLastActiveAt()->getTimestamp()
                        : null,
                ],
                true,
                'user_status_changed',
            );

            return new Response(null, 204);
        }

        return new Response($this->translator->trans('Statut invalide.'), 400);
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
    public function apiUsers(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $q = $request->query->get('q', '');
        $qb = $entityManager->getRepository(\App\Entity\User::class)->createQueryBuilder('u');
        if ($q !== '') {
            $qb->where('LOWER(u.username) LIKE :q OR LOWER(u.displayName) LIKE :q')->setParameter(
                'q',
                '%'.mb_strtolower($q).'%',
            );
        }
        $users = $qb->getQuery()->getResult();

        $data = [];
        foreach ($users as $user) {
            $data[] = [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'displayName' => $user->getDisplayName(),
                'hue' => $user->getHue(),
            ];
        }

        return new JsonResponse($data);
    }

    #[Route('/api/users-options', name: 'app_api_users_options', methods: ['GET'])]
    public function apiUsersOptions(EntityManagerInterface $entityManager): Response
    {
        $users = $entityManager->getRepository(\App\Entity\User::class)->findBy([], ['displayName' => 'ASC']);

        return $this->render('api/_user_options.html.twig', [
            'users' => $users,
        ]);
    }

    // -------------------------------------------------------------------------
    // API: list channels
    // -------------------------------------------------------------------------

    #[Route('/api/channels', name: 'app_api_channels', methods: ['GET'])]
    public function apiChannels(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return new JsonResponse([], Response::HTTP_UNAUTHORIZED);
        }

        $q = $request->query->get('q', '');
        $qb = $entityManager
            ->getRepository(\App\Entity\Channel::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.members', 'm')
            ->where('c.isDm = false')
            ->andWhere('c.isPrivate = false OR m.id = :userId')
            ->setParameter('userId', $currentUser->getId());

        if ($q !== '') {
            $qb->andWhere('LOWER(c.name) LIKE :q OR LOWER(c.slug) LIKE :q')->setParameter(
                'q',
                '%'.mb_strtolower($q).'%',
            );
        }

        $channels = $qb->orderBy('LOWER(c.name)', 'ASC')->getQuery()->getResult();

        $data = [];
        foreach ($channels as $channel) {
            $data[] = [
                'id' => $channel->getId(),
                'name' => $channel->getName(),
                'slug' => $channel->getSlug(),
                'description' => $channel->getDescription(),
            ];
        }

        return new JsonResponse($data);
    }

    // -------------------------------------------------------------------------
    // API: autocomplete HTML fragments (for @mention and #channel)
    // -------------------------------------------------------------------------

    #[Route('/api/autocomplete/{type}', name: 'app_api_autocomplete', methods: ['GET'])]
    public function apiAutocomplete(string $type, Request $request, EntityManagerInterface $entityManager): Response
    {
        $q = $request->query->get('q', '');

        if ($type === 'users') {
            $qb = $entityManager->getRepository(\App\Entity\User::class)->createQueryBuilder('u');
            if ($q !== '') {
                $qb->where('LOWER(u.username) LIKE :q OR LOWER(u.displayName) LIKE :q')->setParameter(
                    'q',
                    '%'.mb_strtolower($q).'%',
                );
            }

            return $this->render('api/_autocomplete_items.html.twig', [
                'type' => 'users',
                'users' => $qb->getQuery()->getResult(),
            ]);
        }

        $currentUser = $this->getUser();
        $qb = $entityManager
            ->getRepository(\App\Entity\Channel::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.members', 'm')
            ->where('c.isDm = false')
            ->andWhere('c.isPrivate = false OR m.id = :userId')
            ->setParameter('userId', $currentUser->getId());

        if ($q !== '') {
            $qb->andWhere('LOWER(c.name) LIKE :q OR LOWER(c.slug) LIKE :q')->setParameter(
                'q',
                '%'.mb_strtolower($q).'%',
            );
        }

        return $this->render('api/_autocomplete_items.html.twig', [
            'type' => 'channels',
            'channels' => $qb->orderBy('LOWER(c.name)', 'ASC')->getQuery()->getResult(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Pin/Unpin message (moved from DashboardController, logically user/channel action)
    // -------------------------------------------------------------------------

    #[Route('/messages/{id}/pin', name: 'app_message_pin', methods: ['POST'])]
    public function pinMessage(
        int $id,
        \App\Repository\MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        MercurePublisher $mercurePublisher,
    ): Response {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channel = $message->getChannel();
        if ($channel->getCreator() !== $currentUser) {
            return new Response($this->translator->trans('Seul le créateur du canal peut épingler un message.'), 403);
        }

        $previousPinnedMessage = $channel->getPinnedMessage();
        $channel->setPinnedMessage($message);
        $entityManager->flush();

        $bannerHtml = $this->renderView('dashboard/_pinned_banner.html.twig', [
            'pinnedMessage' => $message,
            'activeChannel' => $channel,
        ]);
        $bannerOob = '<div id="pinned-banner-container" hx-swap-oob="true">'.$bannerHtml.'</div>';
        $messageHtml = $this->renderMessageItem($message, true);

        $previousMessageHtml = '';
        if ($previousPinnedMessage) {
            $previousMessageHtml = $this->renderMessageItem($previousPinnedMessage, true);
        }

        $mercurePublisher->publishToChannel(
            $channel,
            $bannerOob.$messageHtml.$previousMessageHtml,
            'message_'.$channel->getSlug(),
        );

        return new Response($bannerHtml);
    }

    #[Route('/messages/{id}/unpin', name: 'app_message_unpin', methods: ['POST'])]
    public function unpinMessage(
        int $id,
        \App\Repository\MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        MercurePublisher $mercurePublisher,
    ): Response {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channel = $message->getChannel();
        if ($channel->getCreator() !== $currentUser) {
            return new Response(
                $this->translator->trans('Seul le créateur du canal peut désépingler un message.'),
                403,
            );
        }

        if ($channel->getPinnedMessage() === $message) {
            $channel->setPinnedMessage(null);
            $entityManager->flush();

            $bannerOob = '<div id="pinned-banner-container" hx-swap-oob="true"></div>';
            $messageHtml = $this->renderMessageItem($message, true);

            $mercurePublisher->publishToChannel($channel, $bannerOob.$messageHtml, 'message_'.$channel->getSlug());
        }

        return new Response('');
    }

    // -------------------------------------------------------------------------
    // Private helper
    // -------------------------------------------------------------------------

    private function renderMessageItem(\App\Entity\Message $message, bool $oob = false): string
    {
        return $this->renderView('dashboard/_feed_item.html.twig', [
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
            'oob' => $oob,
        ]);
    }
}
