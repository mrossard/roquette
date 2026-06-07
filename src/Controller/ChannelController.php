<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\MessageRendererTrait;
use App\Entity\Channel;
use App\Entity\UserChannelRead;
use App\Repository\ChannelRepository;
use App\Repository\InvitationRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\MercurePublisher;
use App\Service\ReadTrackingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;

#[IsGranted('ROLE_USER')]
final class ChannelController extends AbstractController
{
    use MessageRendererTrait;

    public function __construct(
        private MercurePublisher $mercurePublisher,
        private ReadTrackingService $readTrackingService,
        private LoggerInterface $logger,
        private CacheInterface $cache,
    ) {}

    // -------------------------------------------------------------------------
    // Create channel
    // -------------------------------------------------------------------------

    #[Route('/channels/create', name: 'app_channel_create', methods: ['POST'])]
    public function createChannel(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $name = trim($request->request->get('name', ''));
        $description = trim($request->request->get('description', ''));

        if ($name === '') {
            $this->addFlash('error', 'Le nom du canal ne peut pas être vide.');
            return $this->redirectToRoute('app_dashboard');
        }

        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($name));
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'channel-' . uniqid();
        }

        $existing = $entityManager->getRepository(Channel::class)->findOneBy(['slug' => $slug]);
        if ($existing) {
            $slug = $slug . '-' . rand(100, 999);
        }

        $channel = new Channel();
        $channel->setName($name);
        $channel->setSlug($slug);
        $channel->setDescription($description);
        $channel->setCreator($currentUser);
        $channel->addMember($currentUser);

        $isPrivate = $request->request->getBoolean('isPrivate', false);
        if ($isPrivate) {
            $channel->setIsPrivate(true);
        }

        $retention = $request->request->get('messageRetentionMonths');
        if ($retention !== null && $retention !== '') {
            $retentionVal = (int) $retention;
            $channel->setMessageRetentionMonths($retentionVal === 0 ? null : $retentionVal);
        } else {
            $channel->setMessageRetentionMonths(6);
        }

        $entityManager->persist($channel);
        $entityManager->flush();

        $this->logger->info(sprintf(
            'Channel created: "%s" (slug: "%s", private: %s) by user "%s"',
            $channel->getName(),
            $channel->getSlug(),
            $channel->isPrivate() ? 'yes' : 'no',
            $currentUser->getUsername(),
        ));

        return $this->redirectToRoute('app_channel', ['slug' => $slug]);
    }

    // -------------------------------------------------------------------------
    // Main channel page
    // -------------------------------------------------------------------------

    #[Route('/channels/{slug}', name: 'app_channel', requirements: ['slug' => '^(?!directory$|reorder$|create$|create-modal$)[^/]+$'])]
    public function channel(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        MessageRepository $messageRepository,
        InvitationRepository $invitationRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channels = $channelRepository->findAllForUser($currentUser);

        $activeChannel = null;
        foreach ($channels as $channel) {
            if ($channel->getSlug() !== $slug) {
                continue;
            }

            $activeChannel = $channel;
            break;
        }

        $isMember = true;
        if (!$activeChannel) {
            $existingChannel = $entityManager->getRepository(Channel::class)->findOneBy(['slug' => $slug]);
            if (!$existingChannel) {
                throw $this->createNotFoundException('Canal non trouvé.');
            }
            if ($existingChannel->isPrivate()) {
                $this->addFlash('error', 'Vous n\'avez pas accès à ce canal privé.');
                return $this->redirectToRoute('app_dashboard');
            }
            $activeChannel = $existingChannel;
            $isMember = false;
        }

        $previousChannelSlug = $request->headers->get('X-Previous-Channel');
        if ($previousChannelSlug && $previousChannelSlug !== $slug) {
            $previousChannel = $channelRepository->findOneBy(['slug' => $previousChannelSlug]);
            if ($previousChannel) {
                $this->readTrackingService->markChannelAsRead($currentUser, $previousChannel);
            }
        }

        $this->readTrackingService->ensureUserChannelReads($currentUser, $channels);

        $messages = [];
        $firstUnreadMessageId = null;
        $ucrRepo = $entityManager->getRepository(UserChannelRead::class);

        if ($isMember) {
            /** @var \App\Entity\UserChannelRead|null $activeRead */
            $activeRead = $ucrRepo->findOneBy(['user' => $currentUser, 'channel' => $activeChannel]);
            $lastReadMessageId = $activeRead?->getLastReadMessage()?->getId();

            $jumpTo = $request->query->getInt('jumpTo');
            if ($jumpTo > 0) {
                $messages = $messageRepository->findMessagesAround($activeChannel, $jumpTo, 50);
            } else {
                $messages = $messageRepository->findLatestInChannel($activeChannel, 50);
                $messages = array_reverse($messages);
            }

            if ($lastReadMessageId !== null) {
                foreach ($messages as $msg) {
                    if (!($msg->getId() > $lastReadMessageId && $msg->getAuthor()->getId() !== $currentUser->getId())) {
                        continue;
                    }

                    $firstUnreadMessageId = $msg->getId();
                    break;
                }
            }

            $this->readTrackingService->markChannelAsRead($currentUser, $activeChannel);
        }

        $unreadCounts = $ucrRepo->getUnreadCounts($currentUser);

        $usersToInvite = [];

        $pendingInvitations = $invitationRepository->findPendingForUser($currentUser);

        $notificationsEnabled = null;
        if ($isMember) {
            /** @var \App\Entity\UserChannelRead|null $activeRead */
            $activeRead = $ucrRepo->findOneBy(['user' => $currentUser, 'channel' => $activeChannel]);
            $notificationsEnabled = $activeRead ? $activeRead->isNotificationsEnabled() : null;
        }
        if ($notificationsEnabled === null) {
            $notificationsEnabled = $activeChannel->isDm();
        }

        $typingUsers = [];
        if ($isMember && $activeChannel) {
            $cacheKey = 'channel_typing_'.$activeChannel->getSlug();
            $typingUsersFromCache = $this->cache->get($cacheKey, function () {
                return [];
            });

            $now = time();
            $changed = false;
            foreach ($typingUsersFromCache as $username => $info) {
                if ($info['expires_at'] < $now) {
                    unset($typingUsersFromCache[$username]);
                    $changed = true;
                }
            }

            if ($changed) {
                $this->cache->delete($cacheKey);
                $this->cache->get($cacheKey, function () use ($typingUsersFromCache) {
                    return $typingUsersFromCache;
                });
            }

            if ($currentUser) {
                unset($typingUsersFromCache[$currentUser->getUsername()]);
            }

            $typingUsers = array_map(fn($info) => $info['name'], array_values($typingUsersFromCache));
        }

        return $this->render('dashboard/index.html.twig', [
            'channels' => $channels,
            'activeChannel' => $activeChannel,
            'messages' => $messages,
            'topic_url' => $this->getChannelTopicUrl($activeChannel),
            'unreadCounts' => $unreadCounts,
            'firstUnreadMessageId' => $firstUnreadMessageId,
            'usersToInvite' => $usersToInvite,
            'pendingInvitations' => $pendingInvitations,
            'isMember' => $isMember,
            'notificationsEnabled' => $notificationsEnabled,
            'typingUsers' => $typingUsers,
        ]);
    }

    // -------------------------------------------------------------------------
    // Load more messages
    // -------------------------------------------------------------------------

    #[Route('/channels/{slug}/more', name: 'app_channel_load_more', methods: ['GET'])]
    public function loadMore(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $activeChannel = $entityManager->getRepository(Channel::class)->findOneBy(['slug' => $slug]);
        if (!$activeChannel) {
            throw $this->createNotFoundException('Canal non trouvé.');
        }

        $channels = $channelRepository->findAllForUser($currentUser);
        $isMember = false;
        foreach ($channels as $channel) {
            if ($channel->getId() !== $activeChannel->getId()) {
                continue;
            }

            $isMember = true;
            break;
        }

        if (!$isMember) {
            return new Response('Accès interdit', Response::HTTP_FORBIDDEN);
        }

        $beforeId = $request->query->getInt('beforeId');
        if ($beforeId <= 0) {
            return new Response('Paramètre manquant', Response::HTTP_BAD_REQUEST);
        }

        $moreMessages = $messageRepository->findLatestInChannel($activeChannel, 50, $beforeId);
        $moreMessages = array_reverse($moreMessages);

        $hasMore = count($moreMessages) === 50;
        $nextBeforeId = count($moreMessages) > 0 ? $moreMessages[0]->getId() : null;

        return $this->render('dashboard/_more_messages.html.twig', [
            'messages' => $moreMessages,
            'channel' => $activeChannel,
            'hasMore' => $hasMore,
            'nextBeforeId' => $nextBeforeId,
        ]);
    }

    #[Route('/channels/{slug}/sidebar-item', name: 'app_channel_sidebar_item', methods: ['GET'])]
    public function sidebarItem(
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

        if ($channel->isPrivate() && !$channel->getMembers()->contains($currentUser)) {
            return new Response('Non autorisé.', 403);
        }

        $ucrRepo = $entityManager->getRepository(\App\Entity\UserChannelRead::class);
        $unreadCounts = $ucrRepo->getUnreadCounts($currentUser);

        return $this->render('dashboard/_channel_sidebar_item.html.twig', [
            'channel' => $channel,
            'unreadCounts' => $unreadCounts,
            'activeChannel' => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Open DM
    // -------------------------------------------------------------------------

    #[Route('/dm/{username}', name: 'app_dm_open')]
    public function openDm(
        string $username,
        EntityManagerInterface $entityManager,
        ChannelRepository $channelRepository,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $partner = $entityManager->getRepository(\App\Entity\User::class)->findOneBy(['username' => $username]);
        if (!$partner) {
            $this->addFlash('error', 'Utilisateur non trouvé.');
            return $this->redirectToRoute('app_dashboard');
        }

        if ($partner->getId() === $currentUser->getId()) {
            $this->addFlash('error', 'Vous ne pouvez pas envoyer de message direct à vous-même.');
            return $this->redirectToRoute('app_dashboard');
        }

        $dmChannel = $channelRepository->findDmBetween($currentUser, $partner);

        if (!$dmChannel) {
            $dmChannel = new Channel();
            $dmChannel->setIsPrivate(true);
            $dmChannel->setIsDm(true);

            $minId = min($currentUser->getId(), $partner->getId());
            $maxId = max($currentUser->getId(), $partner->getId());
            $slug = sprintf('dm-%d-%d', $minId, $maxId);

            $dmChannel->setSlug($slug);
            $dmChannel->setName(sprintf('%s & %s', $currentUser->getUsername(), $partner->getUsername()));
            $dmChannel->setDescription(sprintf(
                'Conversation privée entre %s et %s',
                $currentUser->getUsername(),
                $partner->getUsername(),
            ));

            $dmChannel->setCreator($currentUser);
            $dmChannel->addMember($currentUser);
            $dmChannel->addMember($partner);

            $entityManager->persist($dmChannel);
            $entityManager->flush();
        } else {
            if (!$dmChannel->getMembers()->contains($currentUser)) {
                $dmChannel->addMember($currentUser);
                $entityManager->flush();
            }
        }

        return $this->redirectToRoute('app_channel', ['slug' => $dmChannel->getSlug()]);
    }

    // -------------------------------------------------------------------------
    // Join / Leave
    // -------------------------------------------------------------------------

    #[Route('/channels/{slug}/join', name: 'app_channel_join', methods: ['POST'])]
    public function joinChannel(
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

        if ($channel->isPrivate()) {
            return new Response('Vous ne pouvez pas rejoindre directement un canal privé.', 403);
        }

        if (!$channel->getMembers()->contains($currentUser)) {
            $channel->addMember($currentUser);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_channel', ['slug' => $slug]);
    }

    #[Route('/channels/{slug}/leave', name: 'app_channel_leave', methods: ['POST'])]
    public function leaveChannel(
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

        if ($channel->getMembers()->contains($currentUser)) {
            $channel->removeMember($currentUser);

            $ucrRepo = $entityManager->getRepository(UserChannelRead::class);
            $ucr = $ucrRepo->findOneBy(['user' => $currentUser, 'channel' => $channel]);
            if ($ucr) {
                $entityManager->remove($ucr);
            }

            $entityManager->flush();
        }

        return $this->redirectToRoute('app_dashboard');
    }

    // -------------------------------------------------------------------------
    // Delete channel
    // -------------------------------------------------------------------------

    #[Route('/channels/{slug}/delete', name: 'app_channel_delete', methods: ['POST'])]
    public function deleteChannel(
        string $slug,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            return $this->redirectToRoute('app_dashboard');
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $isCreator = $channel->getCreator() && $channel->getCreator()->getId() === $currentUser->getId();

        if (!$isAdmin && !$isCreator) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas autorisé à supprimer ce canal.');
        }

        $oobHtml = sprintf(
            '<a id="sidebar-channel-%s" hx-swap-oob="delete"></a>'.
            '<script>if(window.location.pathname === "/channels/%s"){ window.location.href = "/"; }</script>',
            $slug,
            $slug,
        );
        $this->mercurePublisher->publishToChannel($channel, $oobHtml, 'channel_deleted');

        $this->logger->info(sprintf(
            'Channel deleted: "%s" (slug: "%s") by user "%s"',
            $channel->getName(),
            $channel->getSlug(),
            $currentUser->getUsername(),
        ));

        $entityManager->remove($channel);
        $entityManager->flush();

        $this->addFlash('success', sprintf('Le canal "%s" a été supprimé.', $channel->getName()));

        return $this->redirectToRoute('app_dashboard');
    }

    // -------------------------------------------------------------------------
    // Reorder channels
    // -------------------------------------------------------------------------

    #[Route('/channels/reorder', name: 'app_channels_reorder', methods: ['POST'])]
    public function reorderChannels(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $order = $data['order'] ?? null;
        if (!is_array($order)) {
            $order = $request->request->all('order');
            if (empty($order)) {
                $order = $request->request->all();
                if (isset($order['order'])) {
                    $order = $order['order'];
                }
            }
        }

        if (is_array($order)) {
            $order = array_map('intval', $order);
            $currentUser->setChannelOrder($order);
            $entityManager->flush();

            return $this->json(['success' => true]);
        }

        return $this->json(['error' => 'Données invalides.'], 400);
    }

    #[Route('/channels/{slug}/favorite', name: 'app_channel_favorite_toggle', methods: ['POST'])]
    public function toggleFavorite(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        InvitationRepository $invitationRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            return new Response('Canal non trouvé.', 404);
        }

        if ($currentUser->isChannelFavorite($channel)) {
            $currentUser->removeFavoriteChannel($channel);
        } else {
            $currentUser->addFavoriteChannel($channel);
        }

        $entityManager->flush();

        if ($request->headers->has('HX-Request')) {
            $channels = $channelRepository->findAllForUser($currentUser);
            $ucrRepo = $entityManager->getRepository(UserChannelRead::class);
            $unreadCounts = $ucrRepo->getUnreadCounts($currentUser);
            $pendingInvitations = $invitationRepository->findPendingForUser($currentUser);

            $currentUrl = $request->headers->get('HX-Current-URL');
            $activeChannel = null;
            if ($currentUrl) {
                $path = parse_url($currentUrl, PHP_URL_PATH);
                if (preg_match('#^/channels/([a-z0-9-]+)$#', $path, $matches)) {
                    $activeChannel = $channelRepository->findOneBy(['slug' => $matches[1]]);
                }
            }

            $sidebarHtml = $this->renderView('dashboard/_sidebar.html.twig', [
                'channels' => $channels,
                'unreadCounts' => $unreadCounts,
                'activeChannel' => $activeChannel,
                'pendingInvitations' => $pendingInvitations,
            ]);

            // Add hx-swap-oob="true" to the root of the rendered sidebar section
            // Wait, templates/dashboard/_sidebar.html.twig already starts with <section class="card glass-panel sidebar-panel" id="sidebar-panel">.
            // We need to inject hx-swap-oob="true" into it or let HTMX handle it.
            // Actually, we can add hx-swap-oob="true" directly in the template when it's rendered for swap,
            // or simply inject it into the tag here. Let's make sure it has hx-swap-oob="true".
            // Since we want the sidebar to swap OOB, we can inject hx-swap-oob="true" into the section tag:
            $sidebarHtml = preg_replace(
                '/<section class="card glass-panel sidebar-panel" id="sidebar-panel">/',
                '<section class="card glass-panel sidebar-panel" id="sidebar-panel" hx-swap-oob="true">',
                $sidebarHtml,
                1,
            );

            $html = $sidebarHtml;

            $isMember = $activeChannel ? in_array($activeChannel, $channels, true) : false;
            if ($activeChannel && $isMember) {
                $buttonHtml = $this->renderView('dashboard/_favorite_button_oob.html.twig', [
                    'activeChannel' => $activeChannel,
                    'isMember' => true,
                ]);
                $html .= "\n" . $buttonHtml;
            }

            return new Response($html);
        }

        return new Response(null, 204, ['HX-Refresh' => 'true']);
    }

    #[Route('/channels/{slug}/retention', name: 'app_channel_update_retention', methods: ['POST'])]
    public function updateRetention(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            return $this->redirectToRoute('app_dashboard');
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $isCreatorOrAdmin = $channel->isAdministrator($currentUser);

        if (!$isAdmin && !$isCreatorOrAdmin) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas autorisé à modifier la rétention de ce canal.');
        }

        $retention = $request->request->get('messageRetentionMonths');
        if ($retention !== null && $retention !== '') {
            $retentionVal = (int) $retention;
            $channel->setMessageRetentionMonths($retentionVal === 0 ? null : $retentionVal);
        } else {
            $channel->setMessageRetentionMonths(6);
        }

        $entityManager->flush();

        $this->addFlash('success', sprintf(
            'La durée de rétention du canal "%s" a été mise à jour.',
            $channel->getName(),
        ));

        return new Response(null, 204, ['HX-Refresh' => 'true']);
    }

    #[Route('/channels/{slug}/edit', name: 'app_channel_edit', methods: ['POST'])]
    public function editChannel(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            return $this->redirectToRoute('app_dashboard');
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $isCreatorOrAdmin = $channel->isAdministrator($currentUser);
        if (!$isAdmin && !$isCreatorOrAdmin) {
            throw $this->createAccessDeniedException(
                'Vous n\'êtes pas autorisé à modifier les paramètres de ce canal.',
            );
        }

        $name = trim($request->request->get('name', ''));
        $description = trim($request->request->get('description', ''));

        if ($name === '') {
            $this->addFlash('error', 'Le nom du canal ne peut pas être vide.');
            return $this->redirectToRoute('app_channel', ['slug' => $slug]);
        }

        if ($channel->getName() !== $name) {
            $newSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($name));
            $newSlug = trim($newSlug, '-');
            if ($newSlug === '') {
                $newSlug = 'channel-' . uniqid();
            }

            if ($newSlug !== $channel->getSlug()) {
                $existing = $channelRepository->findOneBy(['slug' => $newSlug]);
                if ($existing && $existing->getId() !== $channel->getId()) {
                    $newSlug = $newSlug . '-' . rand(100, 999);
                }
                $channel->setSlug($newSlug);
            }
            $channel->setName($name);
        }

        $channel->setDescription($description);

        $retention = $request->request->get('messageRetentionMonths');
        if ($retention !== null && $retention !== '') {
            $retentionVal = (int) $retention;
            $channel->setMessageRetentionMonths($retentionVal === 0 ? null : $retentionVal);
        } else {
            $channel->setMessageRetentionMonths(6);
        }

        // Process administrators
        $adminIds = $request->request->all('administrators');
        $userRepository = $entityManager->getRepository(\App\Entity\User::class);

        // Remove existing administrators that are not in the submitted list
        foreach ($channel->getAdministrators() as $admin) {
            if (!in_array((string)$admin->getId(), $adminIds, true)) {
                $channel->removeAdministrator($admin);
            }
        }
        // Add new administrators
        foreach ($adminIds as $adminId) {
            $adminUser = $userRepository->find((int)$adminId);
            if ($adminUser && $adminUser !== $channel->getCreator()) {
                if ($channel->getMembers()->contains($adminUser)) {
                    $channel->addAdministrator($adminUser);
                }
            }
        }

        $entityManager->flush();

        $this->addFlash('success', 'Les paramètres du canal ont été modifiés.');

        return $this->redirectToRoute('app_channel', ['slug' => $channel->getSlug()]);
    }

    #[Route('/channels/{slug}/admin-chip-add', name: 'app_channel_admin_chip_add', methods: ['POST'])]
    public function addAdminChip(
        string $slug,
        Request $request,
        UserRepository $userRepository,
        ChannelRepository $channelRepository,
    ): Response {
        $userId = $request->request->get('userId');
        $user = $userRepository->find((int)$userId);
        if (!$user) {
            return new Response('', 400);
        }

        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            return new Response('', 404);
        }

        if (!$channel->getMembers()->contains($user)) {
            return new Response('<script>alert("Cet utilisateur n\'est pas membre de ce canal.");</script>', 200);
        }

        return $this->render('dashboard/_admin_chip.html.twig', [
            'member' => $user,
        ]);
    }

    #[Route('/channels/{slug}/admin-autocomplete', name: 'app_channel_admin_autocomplete', methods: ['GET'])]
    public function adminAutocomplete(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
    ): Response {
        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            return new Response('', 404);
        }

        $query = trim($request->query->get('search', ''));
        if ($query === '') {
            return new Response(
                '<div id="admin-autocomplete-suggestions" class="emoji-autocomplete-dropdown" style="display: none;"></div>',
            );
        }

        // Find channel members matching the query who are not already administrators (including creator)
        $matches = [];
        foreach ($channel->getMembers() as $member) {
            if ($channel->getAdministrators()->contains($member) || $member === $channel->getCreator()) {
                continue;
            }

            $username = strtolower($member->getUsername());
            $displayName = strtolower($member->getDisplayName() ?? '');
            $q = strtolower($query);

            if (str_contains($username, $q) || str_contains($displayName, $q)) {
                $matches[] = $member;
            }
        }

        return $this->render('dashboard/_admin_autocomplete_suggestions.html.twig', [
            'matches' => array_slice($matches, 0, 6),
            'channel' => $channel,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function getChannelTopicUrl(Channel $channel): string
    {
        return $this->mercurePublisher->getChannelTopic($channel);
    }
}
