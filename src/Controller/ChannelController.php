<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Controller\Trait\MessageRendererTrait;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\UserChannelRead;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\InvitationRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\ChannelExportService;
use App\Service\ChannelManager;
use App\Service\MercurePublisher;
use App\Service\ReadTrackingService;
use Doctrine\ORM\EntityManagerInterface;
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
        private CacheInterface $cache,
        private TranslatorInterface $translator,
    ) {}

    #[Route('/channels/create', name: 'app_channel_create', methods: ['POST'])]
    public function createChannel(Request $request, ChannelManager $channelManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $name = trim($request->request->get('name', ''));
        $description = trim($request->request->get('description', ''));

        if ($name === '') {
            $this->addFlash('error', $this->translator->trans('Le nom du canal ne peut pas être vide.'));

            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $channel = $channelManager->create($name, $description, [
                'isPrivate' => $request->request->getBoolean('isPrivate', false),
                'groupIdentifier' => $request->request->get('groupIdentifier', ''),
                'isGroupChannel' => $request->request->getBoolean('isGroupChannel', false),
                'isTodoList' => $request->request->getBoolean('isTodoList', false),
                'retentionMonths' => $request->request->get('messageRetentionMonths'),
            ], $currentUser);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_dashboard');
        }

        return $this->redirectToRoute('app_channel', ['slug' => $channel->getSlug()]);
    }

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
        /** @var User $currentUser */
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
            /** @var UserChannelRead|null $activeRead */
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

        $pendingInvitations = $invitationRepository->findPendingForUser($currentUser);

        $notificationsEnabled = null;
        if ($isMember) {
            /** @var UserChannelRead|null $activeRead */
            $activeRead = $ucrRepo->findOneBy(['user' => $currentUser, 'channel' => $activeChannel]);
            $notificationsEnabled = $activeRead ? $activeRead->isNotificationsEnabled() : null;
        }
        if ($notificationsEnabled === null) {
            $notificationsEnabled = $activeChannel->isDm();
        }

        $typingUsers = $this->getTypingUsers($activeChannel, $currentUser, $isMember);

        $subChannelsByParent = $this->buildSubChannelsByParent($channels);

        $messageIds = array_map(static fn(Message $m) => $m->getId(), $messages);
        $replyCounts = $messageRepository->findReplyCounts($messageIds);
        $subchannelByParentMessageId = $channelRepository->findSubchannelsByChannel($activeChannel);

        return $this->render('dashboard/index.html.twig', [
            'channels' => $channels,
            'activeChannel' => $activeChannel,
            'messages' => $messages,
            'topic_url' => $this->getChannelTopicUrl($activeChannel),
            'unreadCounts' => $unreadCounts,
            'firstUnreadMessageId' => $firstUnreadMessageId,
            'usersToInvite' => [],
            'pendingInvitations' => $pendingInvitations,
            'isMember' => $isMember,
            'notificationsEnabled' => $notificationsEnabled,
            'typingUsers' => $typingUsers,
            'subChannelsByParent' => $subChannelsByParent,
            'replyCounts' => $replyCounts,
            'subchannelByParentMessageId' => $subchannelByParentMessageId,
        ]);
    }

    #[Route('/channels/{slug}/more', name: 'app_channel_load_more', methods: ['GET'])]
    public function loadMore(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
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
        $subchannelByParentMessageId = $channelRepository->findSubchannelsByChannel($activeChannel);

        return $this->render('dashboard/_more_messages.html.twig', [
            'messages' => $moreMessages,
            'channel' => $activeChannel,
            'hasMore' => $hasMore,
            'nextBeforeId' => $nextBeforeId,
            'replyCounts' => $replyCounts,
            'subchannelByParentMessageId' => $subchannelByParentMessageId,
        ]);
    }

    #[Route('/channels/{slug}/sidebar-item', name: 'app_channel_sidebar_item', methods: ['GET'])]
    public function sidebarItem(
        string $slug,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            return new Response($this->translator->trans('Canal non trouvé.'), 404);
        }

        if (!$channelRepository->canUserAccess($channel, $currentUser)) {
            return new Response($this->translator->trans('Non autorisé.'), 403);
        }

        $ucrRepo = $entityManager->getRepository(UserChannelRead::class);
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

    #[Route('/dm/{username}', name: 'app_dm_open')]
    public function openDm(
        string $username,
        EntityManagerInterface $entityManager,
        ChannelRepository $channelRepository,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $partner = $entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
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

    #[Route('/channels/{slug}/join', name: 'app_channel_join', methods: ['POST'])]
    public function joinChannel(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
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
        /** @var User $currentUser */
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

    #[Route('/channels/{slug}/delete', name: 'app_channel_delete', methods: ['POST'])]
    public function deleteChannel(string $slug, ChannelManager $channelManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $channel = $channelManager->findChannelBySlug($slug);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $redirectSlug = $channelManager->delete($channel, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }

        $this->addFlash('success', $this->translator->trans('Le canal "%channelName%" a été supprimé.', [
            '%channelName%' => $channel->getName(),
        ]));

        if ($redirectSlug !== 'dashboard') {
            return $this->redirectToRoute('app_channel', ['slug' => $redirectSlug]);
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/channels/reorder', name: 'app_channels_reorder', methods: ['POST'])]
    public function reorderChannels(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $order = $data['order'] ?? null;
        if (!is_array($order)) {
            $order = $request->request->all('order');
            if ($order === null || $order === '') {
                $order = $request->request->all();
                if (is_array($order) && array_key_exists('order', $order)) {
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
        /** @var User $currentUser */
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

            $sidebarHtml = preg_replace(
                '/<section class="card glass-panel sidebar-panel" id="sidebar-panel">/',
                '<section class="card glass-panel sidebar-panel" id="sidebar-panel" hx-swap-oob="true">',
                $sidebarHtml,
                1,
            );

            $html = $sidebarHtml;

            $isMember = $activeChannel !== null && in_array($activeChannel, $channels, true);
            if ($activeChannel && $isMember) {
                $html .= "\n" . $this->renderView('dashboard/_favorite_button_oob.html.twig', [
                    'activeChannel' => $activeChannel,
                    'isMember' => true,
                ]);
            }

            return new Response($html);
        }

        return new Response(null, 204, ['HX-Refresh' => 'true']);
    }

    #[Route('/channels/{slug}/retention', name: 'app_channel_update_retention', methods: ['POST'])]
    public function updateRetention(string $slug, Request $request, ChannelManager $channelManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $channel = $channelManager->findChannelBySlug($slug);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return $this->redirectToRoute('app_dashboard');
        }

        $retention = $request->request->get('messageRetentionMonths');
        $retentionVal = null;
        if ($retention !== null && $retention !== '') {
            $retentionVal = (int) $retention;
        }

        try {
            $channelManager->updateRetention($channel, $retentionVal, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }

        $this->addFlash('success', $this->translator->trans('La durée de rétention du canal "%channelName%" a été mise à jour.', [
            '%channelName%' => $channel->getName(),
        ]));

        return new Response(null, 204, ['HX-Refresh' => 'true']);
    }

    #[Route('/channels/{slug}/edit', name: 'app_channel_edit', methods: ['POST'])]
    public function editChannel(string $slug, Request $request, ChannelManager $channelManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $channel = $channelManager->findChannelBySlug($slug);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return $this->redirectToRoute('app_dashboard');
        }

        $name = trim($request->request->get('name', ''));
        $description = trim($request->request->get('description', ''));

        if ($name === '') {
            $this->addFlash('error', $this->translator->trans('Le nom du canal ne peut pas être vide.'));

            return $this->redirectToRoute('app_channel', ['slug' => $slug]);
        }

        try {
            $channelManager->update($channel, $name, $description, [
                'isTodoList' => $request->request->getBoolean('isTodoList', false),
                'retentionMonths' => $request->request->get('messageRetentionMonths'),
                'administratorIds' => $request->request->all('administrators'),
            ], $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }

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

    #[Route('/channels/{slug}/export', name: 'app_channel_export', methods: ['GET'])]
    public function exportChannel(
        string $slug,
        ChannelRepository $channelRepository,
        ChannelExportService $channelExportService,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $channel = $this->findAndAuthorizeChannel($slug, $channelRepository);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$channel->isAdministrator($currentUser)) {
            throw $this->createAccessDeniedException($this->translator->trans('Non autorisé à exporter l\'historique de ce canal.'));
        }

        return $channelExportService->export($channel, $currentUser);
    }

    #[Route('/sidebar/filter-channels', name: 'app_sidebar_filter_channels', methods: ['GET'])]
    public function filterChannels(
        Request $request,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $query = trim($request->query->get('q', ''));
        $channels = $channelRepository->findAllForUser($currentUser);

        if ($query !== '') {
            $channels = array_filter($channels, static fn(Channel $c) => stripos($c->getName() ?? '', $query) !== false);
        }

        $subChannelsByParent = $this->buildSubChannelsByParent($channels);

        $ucrRepo = $entityManager->getRepository(UserChannelRead::class);
        $unreadCounts = $ucrRepo->getUnreadCounts($currentUser);

        $currentUrl = $request->headers->get('HX-Current-URL');
        $activeChannel = null;
        if ($currentUrl) {
            $path = parse_url($currentUrl, PHP_URL_PATH);
            if (preg_match('#^/channels/([a-z0-9-]+)$#', $path, $matches)) {
                $activeChannel = $channelRepository->findOneBy(['slug' => $matches[1]]);
            }
        }

        return $this->render('dashboard/_sidebar_filter_results.html.twig', [
            'channels' => $channels,
            'subChannelsByParent' => $subChannelsByParent,
            'unreadCounts' => $unreadCounts,
            'activeChannel' => $activeChannel,
            'filterMode' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function getChannelTopicUrl(Channel $channel): string
    {
        return $this->mercurePublisher->getChannelTopic($channel);
    }

    /** @param Channel[] $channels */
    private function buildSubChannelsByParent(array $channels): array
    {
        $map = [];
        foreach ($channels as $ch) {
            if (!$ch->isSubChannel() || !$ch->getParentMessage()) {
                continue;
            }

            $parentId = $ch->getParentMessage()->getChannel()->getId();
            $map[$parentId][] = $ch;
        }

        return $map;
    }

    private function getTypingUsers(?Channel $channel, User $currentUser, bool $isMember): array
    {
        if (!$isMember || !$channel) {
            return [];
        }

        $cacheKey = 'channel_typing_' . $channel->getSlug();
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

        unset($typingUsersFromCache[$currentUser->getUsername()]);

        return array_map(static fn($info) => $info['name'], array_values($typingUsersFromCache));
    }
}
