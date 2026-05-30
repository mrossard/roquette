<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\UserChannelRead;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\MercurePublisher;
use App\Service\ReadTrackingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class NotificationController extends AbstractController
{
    // -------------------------------------------------------------------------
    // Mark channel as read
    // -------------------------------------------------------------------------

    #[Route('/channels/{slug}/read', name: 'app_channel_read', methods: ['POST'])]
    public function markAsRead(
        string $slug,
        ChannelRepository $channelRepository,
        ReadTrackingService $readTrackingService,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $activeChannel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$activeChannel) {
            return new Response('Canal non trouvé.', 404);
        }

        if ($activeChannel->isPrivate() && !$activeChannel->getMembers()->contains($currentUser)) {
            return new Response('Non autorisé.', 403);
        }

        $readTrackingService->markChannelAsRead($currentUser, $activeChannel);

        return new Response('', 204);
    }

    // -------------------------------------------------------------------------
    // Unread messages feed
    // -------------------------------------------------------------------------

    #[Route('/channels/{slug}/unread', name: 'app_channel_unread', methods: ['GET'])]
    public function unreadMessages(
        string $slug,
        ChannelRepository $channelRepository,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $activeChannel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$activeChannel) {
            return new Response('Canal non trouvé.', 404);
        }

        if ($activeChannel->isPrivate() && !$activeChannel->getMembers()->contains($currentUser)) {
            return new Response('Non autorisé.', 403);
        }

        $ucrRepo = $entityManager->getRepository(UserChannelRead::class);
        /** @var \App\Entity\UserChannelRead|null $activeRead */
        $activeRead = $ucrRepo->findOneBy(['user' => $currentUser, 'channel' => $activeChannel]);
        $lastReadMessageId = $activeRead?->getLastReadMessage()?->getId();

        $messages = $messageRepository->findUnreadInChannel($activeChannel, $currentUser, $lastReadMessageId);

        return $this->render('dashboard/_messages_feed.html.twig', [
            'messages' => $messages,
            'activeChannel' => $activeChannel,
            'firstUnreadMessageId' => null,
            'unreadFilterActive' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Search in channel
    // -------------------------------------------------------------------------

    #[Route('/channels/{slug}/search', name: 'app_channel_search', methods: ['GET'])]
    public function search(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        MessageRepository $messageRepository,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $activeChannel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$activeChannel) {
            return new Response('Canal non trouvé.', 404);
        }

        if ($activeChannel->isPrivate() && !$activeChannel->getMembers()->contains($currentUser)) {
            return new Response('Non autorisé.', 403);
        }

        $query = trim($request->query->get('q', ''));

        if ($query === '') {
            $messages = $messageRepository->findBy(
                ['channel' => $activeChannel, 'parent' => null],
                ['createdAt' => 'DESC'],
                50,
            );
            $messages = array_reverse($messages);

            return $this->render('dashboard/_messages_feed.html.twig', [
                'messages' => $messages,
                'activeChannel' => $activeChannel,
                'firstUnreadMessageId' => null,
            ]);
        }

        $messages = $messageRepository->searchInChannel($activeChannel, $query);

        return $this->render('dashboard/_messages_feed.html.twig', [
            'messages' => $messages,
            'activeChannel' => $activeChannel,
            'searchQuery' => $query,
            'firstUnreadMessageId' => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Toggle channel notifications
    // -------------------------------------------------------------------------

    #[Route('/channels/{slug}/toggle-notifications', name: 'app_channel_toggle_notifications', methods: ['POST'])]
    public function toggleNotifications(
        string $slug,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            return new Response('Canal non trouvé.', 404);
        }

        $ucrRepo = $entityManager->getRepository(UserChannelRead::class);
        $ucr = $ucrRepo->findOneBy(['user' => $currentUser, 'channel' => $channel]);
        if (!$ucr) {
            $ucr = new UserChannelRead();
            $ucr->setUser($currentUser);
            $ucr->setChannel($channel);
            $entityManager->persist($ucr);
        }

        $currentStatus = $ucr->isNotificationsEnabled();
        if ($currentStatus === null) {
            $currentStatus = $channel->isDm();
        }

        $newStatus = !$currentStatus;
        $ucr->setNotificationsEnabled($newStatus);
        $entityManager->flush();

        return $this->render('dashboard/_notification_toggle.html.twig', [
            'activeChannel' => $channel,
            'notificationsEnabled' => $newStatus,
        ]);
    }

    // -------------------------------------------------------------------------
    // Typing indicator
    // -------------------------------------------------------------------------

    #[Route('/channel/{slug}/typing', name: 'app_channel_typing', methods: ['POST'])]
    public function typing(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        MercurePublisher $mercurePublisher,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $activeChannel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$activeChannel) {
            return new Response('Canal non trouvé.', 404);
        }

        if ($activeChannel->isPrivate() && !$activeChannel->getMembers()->contains($currentUser)) {
            return new Response('Non autorisé.', 403);
        }

        $data = json_decode($request->getContent(), true);
        $isTyping = filter_var($data['isTyping'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $mercurePublisher->publishToChannel($activeChannel, [
            'type' => 'user_typing',
            'username' => $currentUser->getUsername(),
            'displayName' => ($currentUser->getDisplayName() !== null && $currentUser->getDisplayName() !== '') ? $currentUser->getDisplayName() : $currentUser->getUsername(),
            'isTyping' => $isTyping,
            'channelSlug' => $activeChannel->getSlug(),
        ]);

        return new Response('', 204);
    }

    #[Route('/search', name: 'app_global_search', methods: ['GET'])]
    public function globalSearch(
        Request $request,
        UserRepository $userRepository,
        ChannelRepository $channelRepository,
        MessageRepository $messageRepository,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $rawQuery = trim($request->query->get('q', ''));

        if ($rawQuery === '') {
            return $this->render('dashboard/_global_search_results.html.twig', [
                'channels' => [],
                'users' => [],
                'messages' => [],
                'query' => $rawQuery,
            ]);
        }

        $authorUsername = null;
        $channelName = null;
        $hasFile = null;
        $fileType = null;
        $textQuery = $rawQuery;

        // Parse from:filter
        if (preg_match('/from:([^\s"]+|"[^"]+")/', $textQuery, $matches)) {
            $authorUsername = trim($matches[1], '"@');
            $textQuery = str_replace($matches[0], '', $textQuery);
        }

        // Parse in:filter
        if (preg_match('/in:([^\s"]+|"[^"]+")/', $textQuery, $matches)) {
            $channelName = trim($matches[1], '"#');
            $textQuery = str_replace($matches[0], '', $textQuery);
        }

        // Parse has:filter
        if (preg_match('/has:([^\s]+)/', $textQuery, $matches)) {
            $hasValue = strtolower($matches[1]);
            $hasFile = true;
            if (in_array($hasValue, ['image', 'video', 'audio', 'pdf'], strict: true)) {
                $fileType = $hasValue;
            }
            $textQuery = str_replace($matches[0], '', $textQuery);
        }

        $textQuery = trim($textQuery);

        // Fetch matches
        $channels = [];
        $users = [];
        // Only return channels and users matches if searching with a simple query (no filters)
        if (!$authorUsername && !$channelName && !$hasFile) {
            $channels = $channelRepository->searchByName($textQuery, $currentUser);
            $users = $userRepository->searchByName($textQuery);
        }

        $messages = $messageRepository->searchGlobal(
            $currentUser,
            $authorUsername,
            $channelName,
            $hasFile,
            $fileType,
            $textQuery,
        );

        return $this->render('dashboard/_global_search_results.html.twig', [
            'channels' => $channels,
            'users' => $users,
            'messages' => $messages,
            'query' => $rawQuery,
        ]);
    }
}
