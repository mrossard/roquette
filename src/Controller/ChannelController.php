<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\MessageRendererTrait;
use App\Controller\Trait\ChannelAccessTrait;
use App\Entity\Channel;
use App\Entity\ChannelExport;
use App\Entity\GroupSubscription;
use App\Entity\Message;
use App\Entity\UserChannelRead;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\InvitationRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\MercurePublisher;
use App\Service\ReadTrackingService;
use App\Service\FileUploadService;
use App\Service\AuditLoggerService;
use App\Enum\AuditAction;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class ChannelController extends AbstractController
{
    use MessageRendererTrait;
    use ChannelAccessTrait;

    public function __construct(
        private MercurePublisher $mercurePublisher,
        private ReadTrackingService $readTrackingService,
        private LoggerInterface $logger,
        private CacheInterface $cache,
        private TranslatorInterface $translator,
    ) {}

    // -------------------------------------------------------------------------
    // Create channel
    // -------------------------------------------------------------------------

    #[Route('/channels/create', name: 'app_channel_create', methods: ['POST'])]
    public function createChannel(Request $request, EntityManagerInterface $entityManager, AuditLoggerService $auditLogger): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $name = trim($request->request->get('name', ''));
        $description = trim($request->request->get('description', ''));

        if ($name === '') {
            $this->addFlash('error', $this->translator->trans('Le nom du canal ne peut pas être vide.'));
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

            $groupIdentifier = $request->request->get('groupIdentifier');
            if ($groupIdentifier !== null && $groupIdentifier !== '') {
                $isGroupChannel = $request->request->getBoolean('isGroupChannel', false);

                if ($isGroupChannel) {
                    $existingGroupSub = $entityManager->getRepository(GroupSubscription::class)->findOneBy([
                        'groupIdentifier' => $groupIdentifier,
                        'isGroupChannel' => true,
                    ]);
                    if ($existingGroupSub) {
                        $this->addFlash('error', $this->translator->trans('Ce groupe possède déjà un canal officiel.'));
                        return $this->redirectToRoute('app_dashboard');
                    }
                }

                $groupSubscription = new GroupSubscription();
                $groupSubscription->setGroupIdentifier($groupIdentifier);
                $groupSubscription->setIsGroupChannel($isGroupChannel);
                $channel->addGroupSubscription($groupSubscription);
                $entityManager->persist($groupSubscription);
            }
        }

        $isTodoList = $request->request->getBoolean('isTodoList', false);
        if ($isTodoList) {
            $channel->setIsTodoList(true);
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

        $auditLogger->log(AuditAction::CHANNEL_CREATE, $currentUser, [
            'channel_id' => $channel->getId(),
            'channel_name' => $channel->getName(),
            'slug' => $channel->getSlug(),
            'is_private' => $channel->isPrivate(),
        ]);

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

    #[Route('/channels/{slug}', name: 'app_channel', requirements: [
        'slug' => '^(?!directory$|reorder$|create$|create-modal$)[^/]+$',
    ])]
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
                throw $this->createNotFoundException($this->translator->trans('Canal non trouvé.'));
            }
            if ($existingChannel->isPrivate()) {
                $this->addFlash('error', $this->translator->trans('Vous n\'avez pas accès à ce canal privé.'));
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
            $cacheKey = 'channel_typing_' . $activeChannel->getSlug();
            $typingUsersFromCache = $this->cache->get($cacheKey, static fn() => []);

            $now = time();
            $changed = false;
            foreach ($typingUsersFromCache as $username => $info) {
                if ($info['expires_at'] >= $now) {
                    continue;
                }

                unset($typingUsersFromCache[$username]);
                $changed = true;
            }

            if ($changed) {
                $this->cache->delete($cacheKey);
                $this->cache->get($cacheKey, static fn() => $typingUsersFromCache);
            }

            if ($currentUser) {
                unset($typingUsersFromCache[$currentUser->getUsername()]);
            }

            $typingUsers = array_map(static fn($info) => $info['name'], array_values($typingUsersFromCache));
        }

        $subChannelsByParent = $this->buildSubChannelsByParent($channels);

        $messageIds = array_map(static fn(Message $m) => $m->getId(), $messages);
        $replyCounts = $messageRepository->findReplyCounts($messageIds);

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
            'subChannelsByParent' => $subChannelsByParent,
            'replyCounts' => $replyCounts,
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
            throw $this->createNotFoundException($this->translator->trans('Canal non trouvé.'));
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
            return new Response($this->translator->trans('Accès interdit'), Response::HTTP_FORBIDDEN);
        }

        $beforeId = $request->query->getInt('beforeId');
        if ($beforeId <= 0) {
            return new Response($this->translator->trans('Paramètre manquant'), Response::HTTP_BAD_REQUEST);
        }

        $moreMessages = $messageRepository->findLatestInChannel($activeChannel, 50, $beforeId);
        $moreMessages = array_reverse($moreMessages);

        $hasMore = count($moreMessages) === 50;
        $nextBeforeId = count($moreMessages) > 0 ? $moreMessages[0]->getId() : null;

        $messageIds = array_map(static fn(Message $m) => $m->getId(), $moreMessages);
        $replyCounts = $messageRepository->findReplyCounts($messageIds);

        return $this->render('dashboard/_more_messages.html.twig', [
            'messages' => $moreMessages,
            'channel' => $activeChannel,
            'hasMore' => $hasMore,
            'nextBeforeId' => $nextBeforeId,
            'replyCounts' => $replyCounts,
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
            return new Response($this->translator->trans('Canal non trouvé.'), 404);
        }

        if (!$channelRepository->canUserAccess($channel, $currentUser)) {
            return new Response($this->translator->trans('Non autorisé.'), 403);
        }

        $ucrRepo = $entityManager->getRepository(\App\Entity\UserChannelRead::class);
        $unreadCounts = $ucrRepo->getUnreadCounts($currentUser);

        $template = $channel->isSubChannel()
            ? 'dashboard/_subchannel_sidebar_item.html.twig'
            : 'dashboard/_channel_sidebar_item.html.twig';

        return $this->render($template, [
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
            $this->addFlash('error', $this->translator->trans('Utilisateur non trouvé.'));
            return $this->redirectToRoute('app_dashboard');
        }

        if ($partner->getId() === $currentUser->getId()) {
            $this->addFlash(
                'error',
                $this->translator->trans('Vous ne pouvez pas envoyer de message direct à vous-même.'),
            );
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
        Request $request,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            return new Response($this->translator->trans('Canal non trouvé.'), 404);
        }

        if ($channel->isPrivate()) {
            return new Response(
                $this->translator->trans('Vous ne pouvez pas rejoindre directement un canal privé.'),
                403,
            );
        }

        if (!$channel->getMembers()->contains($currentUser)) {
            $channel->addMember($currentUser);
            $entityManager->flush();
        }

        $url = $this->generateUrl('app_channel', ['slug' => $slug]);
        if ($request->headers->has('HX-Request')) {
            return new Response(null, 204, ['HX-Redirect' => $url]);
        }

        return $this->redirect($url);
    }

    #[Route('/channels/{slug}/leave', name: 'app_channel_leave', methods: ['POST'])]
    public function leaveChannel(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            return new Response($this->translator->trans('Canal non trouvé.'), 404);
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

        $url = $this->generateUrl('app_dashboard');
        if ($request->headers->has('HX-Request')) {
            return new Response(null, 204, ['HX-Redirect' => $url]);
        }

        return $this->redirect($url);
    }

    // -------------------------------------------------------------------------
    // Delete channel
    // -------------------------------------------------------------------------

    #[Route('/channels/{slug}/delete', name: 'app_channel_delete', methods: ['POST'])]
    public function deleteChannel(
        string $slug,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
        AuditLoggerService $auditLogger,
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
            throw $this->createAccessDeniedException($this->translator->trans(
                'Vous n\'êtes pas autorisé à supprimer ce canal.',
            ));
        }

        $parentChannel = $channel->getParentMessage()?->getChannel();

        $redirectSlug = $parentChannel ? '/channels/' . $parentChannel->getSlug() : '/';

        $this->mercurePublisher->publishToChannel(
            $channel,
            [
                'channelSlug' => $slug,
                'redirectUrl' => $redirectSlug,
            ],
            'channel_deleted',
        );

        $auditLogger->log(AuditAction::CHANNEL_DELETE, $currentUser, [
            'channel_id' => $channel->getId(),
            'channel_name' => $channel->getName(),
            'slug' => $channel->getSlug(),
            'is_subchannel' => $channel->isSubChannel(),
        ]);

        $this->logger->info(sprintf(
            'Channel deleted: "%s" (slug: "%s") by user "%s"',
            $channel->getName(),
            $channel->getSlug(),
            $currentUser->getUsername(),
        ));

        $entityManager->remove($channel);
        $entityManager->flush();

        $this->addFlash('success', $this->translator->trans('Le canal "%channelName%" a été supprimé.', [
            '%channelName%' => $channel->getName(),
        ]));

        if ($parentChannel) {
            return $this->redirectToRoute('app_channel', [
                'slug' => $parentChannel->getSlug(),
            ]);
        }

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

        return $this->json(['error' => $this->translator->trans('Données invalides.')], 400);
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
            return new Response($this->translator->trans('Canal non trouvé.'), 404);
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

            $subChannelsByParent = $this->buildSubChannelsByParent($channels);

            $sidebarHtml = $this->renderView('dashboard/_sidebar.html.twig', [
                'channels' => $channels,
                'unreadCounts' => $unreadCounts,
                'activeChannel' => $activeChannel,
                'pendingInvitations' => $pendingInvitations,
                'subChannelsByParent' => $subChannelsByParent,
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
            throw $this->createAccessDeniedException($this->translator->trans(
                'Vous n\'êtes pas autorisé à modifier la rétention de ce canal.',
            ));
        }

        $retention = $request->request->get('messageRetentionMonths');
        if ($retention !== null && $retention !== '') {
            $retentionVal = (int) $retention;
            $channel->setMessageRetentionMonths($retentionVal === 0 ? null : $retentionVal);
        } else {
            $channel->setMessageRetentionMonths(6);
        }

        $entityManager->flush();

        $this->addFlash('success', $this->translator->trans('La durée de rétention du canal "%channelName%" a été mise à jour.', [
            '%channelName%' => $channel->getName(),
        ]));

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
            throw $this->createAccessDeniedException($this->translator->trans(
                'Vous n\'êtes pas autorisé à modifier les paramètres de ce canal.',
            ));
        }

        $name = trim($request->request->get('name', ''));
        $description = trim($request->request->get('description', ''));

        if ($name === '') {
            $this->addFlash('error', $this->translator->trans('Le nom du canal ne peut pas être vide.'));
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

        if ($channel->isSubChannel()) {
            $isTodoList = $request->request->getBoolean('isTodoList', false);
            $channel->setIsTodoList($isTodoList);
        }

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
            if (in_array((string) $admin->getId(), $adminIds, true)) {
                continue;
            }

            $channel->removeAdministrator($admin);
        }
        // Add new administrators
        foreach ($adminIds as $adminId) {
            $adminUser = $userRepository->find((int) $adminId);
            if ($adminUser && $adminUser !== $channel->getCreator()) {
                if ($channel->getMembers()->contains($adminUser)) {
                    $channel->addAdministrator($adminUser);
                }
            }
        }

        $entityManager->flush();

        $this->addFlash('success', $this->translator->trans('Les paramètres du canal ont été modifiés.'));

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
        $user = $userRepository->find((int) $userId);
        if (!$user) {
            return new Response('', 400);
        }

        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            return new Response('', 404);
        }

        if (!$channel->getMembers()->contains($user)) {
            return new Response(
                '<script>alert("'
                . $this->translator->trans("Cet utilisateur n'est pas membre de ce canal.")
                . '");</script>',
                200,
            );
        }

        return $this->render('dashboard/_admin_chip.html.twig', [
            'member' => $user,
        ]);
    }

    #[Route('/channels/{slug}/admin-autocomplete', name: 'app_channel_admin_autocomplete', methods: ['GET'])]
    public function adminAutocomplete(string $slug, Request $request, ChannelRepository $channelRepository): Response
    {
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
    // Sub-channel creation
    // -------------------------------------------------------------------------

    #[Route('/messages/{id}/sub-channel', name: 'app_message_create_subchannel', methods: ['POST'])]
    public function createSubChannel(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        AuditLoggerService $auditLogger,
    ): Response {
        return $this->doCreateSubChannel($id, $request, $messageRepository, $entityManager, $auditLogger, false);
    }

    #[Route('/messages/{id}/sub-channel-todo', name: 'app_message_create_subchannel_todo', methods: ['POST'])]
    public function createSubChannelTodo(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        AuditLoggerService $auditLogger,
    ): Response {
        return $this->doCreateSubChannel($id, $request, $messageRepository, $entityManager, $auditLogger, true);
    }

    private function doCreateSubChannel(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        AuditLoggerService $auditLogger,
        bool $isTodoList,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $parentMessage = $messageRepository->find($id);
        if (!$parentMessage) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        // Check if a subchannel for this message already exists
        $existingSubChannel = $entityManager
            ->getRepository(Channel::class)
            ->findOneBy(['parentMessage' => $parentMessage]);
        if ($existingSubChannel) {
            $url = $this->generateUrl('app_channel', ['slug' => $existingSubChannel->getSlug()]);
            if ($request->headers->has('HX-Request')) {
                return new Response(null, 204, ['HX-Redirect' => $url]);
            }

            return $this->redirect($url);
        }

        $parentChannel = $parentMessage->getChannel();
        if ($parentChannel->isSubChannel() && !$parentChannel->isTodoList()) {
            return new Response($this->translator->trans('Non autorisé.'), 403);
        }

        $channelRepository = $entityManager->getRepository(Channel::class);
        if (!$channelRepository->canUserAccess($parentChannel, $currentUser)) {
            return new Response($this->translator->trans('Non autorisé.'), 403);
        }

        // Build sub-channel name from the message
        $content = $parentMessage->getContent() ?? $parentMessage->getFileName() ?? 'Discussion';
        $name = mb_substr(trim(preg_replace('/\s+/', ' ', $content)), 0, 40);

        $slug =
            'sc-'
            . preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($name))
            . '-'
            . substr(bin2hex(random_bytes(3)), 0, 6);
        $slug = trim($slug, '-');

        if ($entityManager->getRepository(Channel::class)->findOneBy(['slug' => $slug])) {
            $slug .= '-' . rand(100, 999);
        }

        $channel = new Channel();
        $channel->setName($name);
        $channel->setSlug($slug);
        $channel->setDescription($this->translator->trans('Discussion créée depuis un message.'));
        $channel->setParentMessage($parentMessage);
        $channel->setCreator($currentUser);
        $channel->setIsPrivate($parentChannel->isPrivate());
        $channel->setMessageRetentionMonths($parentChannel->getMessageRetentionMonths());
        if ($isTodoList) {
            $channel->setIsTodoList(true);
        }

        // Copy all members from the parent channel
        foreach ($parentChannel->getMembers() as $member) {
            $channel->addMember($member);
        }

        $entityManager->persist($channel);
        $entityManager->flush();

        $auditLogger->log(AuditAction::CHANNEL_CREATE, $currentUser, [
            'channel_id' => $channel->getId(),
            'channel_name' => $channel->getName(),
            'slug' => $channel->getSlug(),
            'is_private' => $channel->isPrivate(),
            'parent_channel_id' => $parentChannel->getId(),
            'parent_message_id' => $parentMessage->getId(),
        ]);

        $this->logger->info(sprintf(
            'Sub-channel created: "%s" (slug: "%s", todo: %s) from message #%d by user "%s"',
            $channel->getName(),
            $channel->getSlug(),
            $isTodoList ? 'yes' : 'no',
            $parentMessage->getId(),
            $currentUser->getUsername(),
        ));

        $this->addFlash('success', $this->translator->trans('Discussion "%channelName%" créée.', [
            '%channelName%' => $channel->getName(),
        ]));

        $url = $this->generateUrl('app_channel', ['slug' => $channel->getSlug()]);
        if ($request->headers->has('HX-Request')) {
            return new Response(null, 204, ['HX-Redirect' => $url]);
        }

        return $this->redirect($url);
    }

    #[Route('/channels/{slug}/subscribe-group', name: 'app_channel_subscribe_group', methods: ['POST'])]
    public function subscribeGroup(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $channel = $channelRepository->findOneBy(['slug' => $slug]);

        if (!$channel) {
            return new Response('Canal non trouvé', 404);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$channel->isAdministrator($currentUser)) {
            return new Response('Accès refusé', 403);
        }

        $newGroupIdentifier = $request->request->get('newGroupIdentifier');
        if ($newGroupIdentifier !== null && $newGroupIdentifier !== '') {
            $isOfficial = $request->request->getBoolean('newGroupIsOfficial', false);

            if ($isOfficial) {
                $existingGroupSub = $entityManager->getRepository(GroupSubscription::class)->findOneBy([
                    'groupIdentifier' => $newGroupIdentifier,
                    'isGroupChannel' => true,
                ]);
                if ($existingGroupSub) {
                    $this->addFlash('error', $this->translator->trans('Ce groupe possède déjà un canal officiel.'));
                    return $this->forward(ModalController::class . '::editModal', ['slug' => $slug]);
                }
            }

            $existingSub = $entityManager->getRepository(GroupSubscription::class)->findOneBy([
                'channel' => $channel,
                'groupIdentifier' => $newGroupIdentifier,
            ]);

            if (!$existingSub) {
                $sub = new GroupSubscription();
                $sub->setGroupIdentifier($newGroupIdentifier);
                $sub->setIsGroupChannel($isOfficial);
                $channel->addGroupSubscription($sub);
                $entityManager->persist($sub);
                $entityManager->flush();
            }
        }

        return $this->forward(ModalController::class . '::editModal', ['slug' => $slug]);
    }

    #[Route('/channels/{slug}/unsubscribe-group/{subscriptionId}', name: 'app_channel_unsubscribe_group', methods: ['POST'])]
    public function unsubscribeGroup(
        string $slug,
        int $subscriptionId,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $channel = $channelRepository->findOneBy(['slug' => $slug]);

        if (!$channel) {
            return new Response('Canal non trouvé', 404);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$channel->isAdministrator($currentUser)) {
            return new Response('Accès refusé', 403);
        }

        $subscription = $entityManager->getRepository(GroupSubscription::class)->find($subscriptionId);
        if ($subscription && $subscription->getChannel() === $channel) {
            $channel->removeGroupSubscription($subscription);
            $entityManager->remove($subscription);
            $entityManager->flush();
        }

        return $this->forward(ModalController::class . '::editModal', ['slug' => $slug]);
    }

    #[Route('/channels/{slug}/export', name: 'app_channel_export', methods: ['GET'])]
    public function exportChannel(
        string $slug,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
        FileUploadService $fileUploadService,
        AuditLoggerService $auditLogger,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $channel = $this->findAndAuthorizeChannel($slug, $channelRepository);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        // Only admins or channel administrators can export
        if (!$this->isGranted('ROLE_ADMIN') && !$channel->isAdministrator($currentUser)) {
            throw $this->createAccessDeniedException($this->translator->trans('Non autorisé à exporter l\'historique de ce canal.'));
        }

        $messages = $entityManager->getRepository(Message::class)->findBy(
            ['channel' => $channel],
            ['createdAt' => 'ASC']
        );

        // Build data structure
        $exportData = [
            'channel' => [
                'id' => $channel->getId(),
                'name' => $channel->getName(),
                'slug' => $channel->getSlug(),
                'description' => $channel->getDescription(),
                'createdAt' => $channel->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'isPrivate' => $channel->isPrivate(),
                'isTodoList' => $channel->isTodoList(),
            ],
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'exportedBy' => $currentUser->getUsername(),
            'messages' => [],
        ];

        foreach ($messages as $msg) {
            $msgData = [
                'id' => $msg->getId(),
                'author' => [
                    'username' => $msg->getAuthor()?->getUsername(),
                    'displayName' => $msg->getAuthor()?->getDisplayName(),
                ],
                'content' => $msg->getContent(),
                'formattedContent' => $msg->getFormattedContent(),
                'createdAt' => $msg->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $msg->getUpdatedAt() ? $msg->getUpdatedAt()->format(\DateTimeInterface::ATOM) : null,
            ];

            if ($msg->getFileName()) {
                $msgData['file'] = [
                    'name' => $msg->getFileName(),
                    'size' => $msg->getFileSize(),
                    'mimeType' => $msg->getMimeType(),
                    'path' => 'files/' . basename($msg->getFilePath()),
                ];
            }

            $exportData['messages'][] = $msgData;
        }

        // Generate HTML content
        $htmlContent = $this->renderView('dashboard/export.html.twig', [
            'channel' => $channel,
            'messages' => $messages,
            'exportData' => $exportData,
        ]);

        if (class_exists(\ZipArchive::class)) {
            return $this->exportAsZip($channel, $currentUser, $exportData, $htmlContent, $messages, $fileUploadService, $entityManager, $auditLogger);
        }

        return $this->exportAsTar($channel, $currentUser, $exportData, $htmlContent, $messages, $fileUploadService, $entityManager, $auditLogger);
    }

    private function saveAndCreateExportEntity(
        Channel $channel,
        User $currentUser,
        string $filename,
        string $fileContentResult,
        string $extension,
        EntityManagerInterface $entityManager,
        FileUploadService $fileUploadService,
        AuditLoggerService $auditLogger,
    ): void {
        $uniqueFilename = $channel->getSlug() . '-' . uniqid() . '.' . $extension;
        $storagePath = 'exports/' . $uniqueFilename;

        $fileUploadService->write($storagePath, $fileContentResult);

        $export = new ChannelExport();
        $export->setChannel($channel);
        $export->setExportedBy($currentUser);
        $export->setFileName($filename);
        $export->setFilePath($storagePath);
        $export->setFileSize(strlen($fileContentResult));
        $export->setChannelName($channel->getName());

        $entityManager->persist($export);
        $entityManager->flush();

        $auditLogger->log(
            AuditAction::CHANNEL_EXPORT,
            $currentUser,
            [
                'channel_id' => $channel->getId(),
                'channel_name' => $channel->getName(),
                'slug' => $channel->getSlug(),
                'export_id' => $export->getId(),
                'file_name' => $filename,
            ]
        );
    }

    private function exportAsZip(
        Channel $channel,
        User $currentUser,
        array $exportData,
        string $htmlContent,
        array $messages,
        FileUploadService $fileUploadService,
        EntityManagerInterface $entityManager,
        AuditLoggerService $auditLogger,
    ): Response {
        $zip = new \ZipArchive();
        $zipFile = tempnam(sys_get_temp_dir(), 'export-');
        if ($zipFile === false || $zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Impossible de créer l\'archive ZIP.');
        }

        $zip->addFromString('channel.json', json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('channel.html', $htmlContent);

        foreach ($messages as $msg) {
            $filePath = $msg->getFilePath();
            if (!$filePath) {
                continue;
            }
            try {
                if ($fileUploadService->exists($filePath)) {
                    $fileStream = $fileUploadService->readStream($filePath);
                    $fileContent = stream_get_contents($fileStream);
                    if (is_resource($fileStream)) {
                        fclose($fileStream);
                    }
                    $zip->addFromString('files/' . basename($filePath), $fileContent);
                }
            } catch (\Exception) {
                // Ignore missing files or read errors to ensure zip continues
                continue;
            }
        }

        $zip->close();
        $fileContentResult = file_get_contents($zipFile);
        unlink($zipFile);

        $filename = $channel->getSlug() . '-export.zip';

        $this->saveAndCreateExportEntity($channel, $currentUser, $filename, $fileContentResult, 'zip', $entityManager, $fileUploadService, $auditLogger);

        $response = new Response($fileContentResult);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set(
            'Content-Disposition',
            \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition(
                \Symfony\Component\HttpFoundation\HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename
            )
        );

        return $response;
    }

    private function exportAsTar(
        Channel $channel,
        User $currentUser,
        array $exportData,
        string $htmlContent,
        array $messages,
        FileUploadService $fileUploadService,
        EntityManagerInterface $entityManager,
        AuditLoggerService $auditLogger,
    ): Response {
        $tarFile = tempnam(sys_get_temp_dir(), 'export-') . '.tar';
        $tar = new \PharData($tarFile);

        $tar->addFromString('channel.json', json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $tar->addFromString('channel.html', $htmlContent);

        foreach ($messages as $msg) {
            $filePath = $msg->getFilePath();
            if (!$filePath) {
                continue;
            }
            try {
                if ($fileUploadService->exists($filePath)) {
                    $fileStream = $fileUploadService->readStream($filePath);
                    $fileContent = stream_get_contents($fileStream);
                    if (is_resource($fileStream)) {
                        fclose($fileStream);
                    }
                    $tar->addFromString('files/' . basename($filePath), $fileContent);
                }
            } catch (\Exception) {
                // Ignore missing files or read errors to ensure tar continues
                continue;
            }
        }

        $fileContentResult = file_get_contents($tarFile);
        unlink($tarFile);

        $filename = $channel->getSlug() . '-export.tar';

        $this->saveAndCreateExportEntity($channel, $currentUser, $filename, $fileContentResult, 'tar', $entityManager, $fileUploadService, $auditLogger);

        $response = new Response($fileContentResult);
        $response->headers->set('Content-Type', 'application/x-tar');
        $response->headers->set(
            'Content-Disposition',
            \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition(
                \Symfony\Component\HttpFoundation\HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename
            )
        );

        return $response;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function getChannelTopicUrl(Channel $channel): string
    {
        return $this->mercurePublisher->getChannelTopic($channel);
    }

    /**
     * @param Channel[] $channels
     * @return array<int, Channel[]>
     */
    private function buildSubChannelsByParent(array $channels): array
    {
        $map = [];
        foreach ($channels as $ch) {
            if (!($ch->isSubChannel() && $ch->getParentMessage())) {
                continue;
            }

            $parentId = $ch->getParentMessage()->getChannel()->getId();
            $map[$parentId][] = $ch;
        }

        return $map;
    }
}
