<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Entity\Message;
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
use Symfony\Contracts\Cache\CacheInterface;

#[IsGranted('ROLE_USER')]
final class NotificationController extends AbstractController
{
    use ChannelAccessTrait;

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

        try {
            $activeChannel = $this->findAndAuthorizeChannel($slug, $channelRepository);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        $readTrackingService->markChannelAsRead($currentUser, $activeChannel);

        return new Response('', 204);
    }

    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // Search and filter in channel
    // -------------------------------------------------------------------------

    #[Route('/channels/{slug}/search', name: 'app_channel_search', methods: ['GET'])]
    public function search(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        try {
            $activeChannel = $this->findAndAuthorizeChannel($slug, $channelRepository);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        $query = trim($request->query->get('q', ''));
        $unread = $request->query->getBoolean('unread', false);

        if ($unread) {
            $ucrRepo = $entityManager->getRepository(UserChannelRead::class);
            /** @var \App\Entity\UserChannelRead|null $activeRead */
            $activeRead = $ucrRepo->findOneBy(['user' => $currentUser, 'channel' => $activeChannel]);
            $lastReadMessageId = $activeRead?->getLastReadMessage()?->getId();

            $messages = $messageRepository->findUnreadInChannel($activeChannel, $currentUser, $lastReadMessageId);

            if ($query !== '') {
                $messages = array_filter(
                    $messages,
                    static fn($m) => (
                        mb_strpos(mb_strtolower($m->getContent(), 'UTF-8'), mb_strtolower($query, 'UTF-8')) !== false
                    ),
                );
            }

            $messageIds = array_map(static fn(Message $m) => (int) $m->getId(), $messages);
            $replyCounts = $messageRepository->findReplyCounts($messageIds);

            return $this->render('dashboard/_messages_feed.html.twig', [
                'messages' => $messages,
                'activeChannel' => $activeChannel,
                'firstUnreadMessageId' => null,
                'unreadFilterActive' => true,
                'searchQuery' => $query !== '' ? $query : null,
                'replyCounts' => $replyCounts,
            ]);
        }

        if ($query === '') {
            $messages = $messageRepository->findLatestInChannel($activeChannel, 50);
            $messages = array_reverse($messages);

            $messageIds = array_map(static fn(Message $m) => (int) $m->getId(), $messages);
            $replyCounts = $messageRepository->findReplyCounts($messageIds);

            return $this->render('dashboard/_messages_feed.html.twig', [
                'messages' => $messages,
                'activeChannel' => $activeChannel,
                'firstUnreadMessageId' => null,
                'replyCounts' => $replyCounts,
            ]);
        }

        $messages = $messageRepository->searchInChannel($activeChannel, $query);

        $messageIds = array_map(static fn(Message $m) => (int) $m->getId(), $messages);
        $replyCounts = $messageRepository->findReplyCounts($messageIds);

        return $this->render('dashboard/_messages_feed.html.twig', [
            'messages' => $messages,
            'activeChannel' => $activeChannel,
            'searchQuery' => $query,
            'firstUnreadMessageId' => null,
            'replyCounts' => $replyCounts,
        ]);
    }

    // -------------------------------------------------------------------------
    // View all replies to a message (thread)
    // -------------------------------------------------------------------------

    #[Route('/messages/{id}/replies', name: 'app_message_replies', methods: ['GET'])]
    public function replies(int $id, MessageRepository $messageRepository): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $message = $messageRepository->find($id);
        if (!$message) {
            throw $this->createNotFoundException('Message not found');
        }

        $channel = $message->getChannel();
        if (!$channel) {
            throw $this->createNotFoundException('Channel not found');
        }

        // Verify access to the channel
        $members = $channel->getMembers();
        $hasAccess = $channel->isDm()
            ? $members->contains($currentUser)
            : !$channel->isPrivate() || $members->contains($currentUser);

        if (!$hasAccess) {
            throw $this->createAccessDeniedException();
        }

        $replies = $messageRepository->findReplyTree($message);

        // Include the original message as the first item
        $messages = array_merge([$message], $replies);

        return $this->render('dashboard/_messages_feed.html.twig', [
            'messages' => $messages,
            'activeChannel' => $channel,
            'firstUnreadMessageId' => null,
            'threadOf' => $message,
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

        try {
            $channel = $this->findAndAuthorizeChannel($slug, $channelRepository);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
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
        CacheInterface $cache,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        try {
            $activeChannel = $this->findAndAuthorizeChannel($slug, $channelRepository);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        $isTyping = filter_var($request->request->get('isTyping', false), FILTER_VALIDATE_BOOLEAN);
        if ($request->headers->get('Content-Type') === 'application/json') {
            $data = json_decode($request->getContent(), true);
            $isTyping = filter_var($data['isTyping'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        $cacheKey = 'channel_typing_' . $activeChannel->getSlug();

        $typingUsers = $cache->get($cacheKey, static fn() => []);

        $now = time();
        $displayName =
            $currentUser->getDisplayName() !== null && $currentUser->getDisplayName() !== ''
                ? $currentUser->getDisplayName()
                : $currentUser->getUsername();

        if ($isTyping) {
            $typingUsers[$currentUser->getUsername()] = [
                'name' => $displayName,
                'expires_at' => $now + 5,
            ];
        } else {
            unset($typingUsers[$currentUser->getUsername()]);
        }

        foreach ($typingUsers as $username => $info) {
            if ($info['expires_at'] >= $now) {
                continue;
            }

            unset($typingUsers[$username]);
        }

        $cache->delete($cacheKey);
        $cache->get($cacheKey, static fn() => $typingUsers);

        $mercurePublisher->publishToChannel($activeChannel, 'ping', 'typing_' . $activeChannel->getSlug());

        return $this->typingIndicator($slug, $channelRepository, $cache);
    }

    #[Route('/channel/{slug}/typing-indicator', name: 'app_channel_typing_indicator', methods: ['GET'])]
    public function typingIndicator(string $slug, ChannelRepository $channelRepository, CacheInterface $cache): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        try {
            $activeChannel = $this->findAndAuthorizeChannel($slug, $channelRepository);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        $cacheKey = 'channel_typing_' . $activeChannel->getSlug();

        $typingUsers = $cache->get($cacheKey, static fn() => []);

        $now = time();
        $changed = false;
        foreach ($typingUsers as $username => $info) {
            if ($info['expires_at'] >= $now) {
                continue;
            }

            unset($typingUsers[$username]);
            $changed = true;
        }

        if ($changed) {
            $cache->delete($cacheKey);
            $cache->get($cacheKey, static fn() => $typingUsers);
        }

        if ($currentUser) {
            unset($typingUsers[$currentUser->getUsername()]);
        }

        $names = array_map(static fn($info) => $info['name'], array_values($typingUsers));

        return $this->render('dashboard/_typing_indicator.html.twig', [
            'typingUsers' => $names,
            'activeChannel' => $activeChannel,
        ]);
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
