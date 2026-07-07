<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Controller\Trait\MessageRendererTrait;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\UserChannelRead;
use App\Repository\ChannelRepository;
use App\Repository\InvitationRepository;
use App\Repository\MessageRepository;
use App\Repository\WorkspaceRepository;
use App\Service\ChannelManager;
use App\Service\MercurePublisher;
use App\Service\ReadTrackingService;
use App\Service\WorkspaceManager;
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
        private readonly MercurePublisher $mercurePublisher,
        private readonly ReadTrackingService $readTrackingService,
        private readonly CacheInterface $cache,
        private readonly TranslatorInterface $translator,
        private readonly ChannelManager $channelManager,
    ) {}

    #[Route('/channels/{slug}', name: 'app_channel', requirements: [
        'slug' => '^(?!directory$|reorder$|create$|create-modal$)[^/]+$',
    ])]
    public function channel(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        MessageRepository $messageRepository,
        InvitationRepository $invitationRepository,
        WorkspaceRepository $workspaceRepository,
        EntityManagerInterface $entityManager,
        WorkspaceManager $workspaceManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $channels = $channelRepository->findAllForUser($currentUser);
        $workspaces = $workspaceRepository->findAllForUser($currentUser);

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

            // Check workspace access
            $workspace = $existingChannel->getWorkspace();
            if ($workspace) {
                if (!$workspaceManager->isUserMember($workspace, $currentUser)) {
                    $this->addFlash('error', $this->translator->trans('Vous n\'avez pas accès à ce canal.'));

                    return $this->redirectToRoute('app_dashboard');
                }
            } elseif ($existingChannel->isPrivate()) {
                $this->addFlash('error', $this->translator->trans('Vous n\'avez pas accès à ce canal privé.'));

                return $this->redirectToRoute('app_dashboard');
            }
            $activeChannel = $existingChannel;
            $isMember =
                $workspace && $workspaceManager->isUserMember($workspace, $currentUser)
                || $existingChannel->getMembers()->contains($currentUser);
        }

        if ($activeChannel && $activeChannel->getWorkspace()) {
            $request->getSession()->set('current_workspace_id', $activeChannel->getWorkspace()->getId());
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
        $workspaceUnreadCounts = $this->computeWorkspaceUnreadCounts($channels, $unreadCounts);

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
        $lastMessages = $messageRepository->findLastMessagesForChannels(array_map(
            static fn(Channel $c) => $c->getId(),
            $channels,
        ));

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
            'lastMessages' => $lastMessages,
            'workspaces' => $workspaces,
            'workspaceUnreadCounts' => $workspaceUnreadCounts,
        ]);
    }

    #[Route('/channels/{slug}/more', name: 'app_channel_load_more', methods: ['GET'])]
    public function loadMore(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        WorkspaceManager $workspaceManager,
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
            // Check workspace membership
            $workspace = $activeChannel->getWorkspace();
            if ($workspace && $workspaceManager->isUserMember($workspace, $currentUser)) {
                $isMember = true;
            }
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
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            return new Response($this->translator->trans('Canal non trouvé.'), 404);
        }

        if (!$this->channelAccessService->canUserAccess($channel, $currentUser)) {
            return new Response($this->translator->trans('Non autorisé.'), 403);
        }

        $ucrRepo = $entityManager->getRepository(UserChannelRead::class);
        $unreadCounts = $ucrRepo->getUnreadCounts($currentUser);

        $lastMessage = $messageRepository->findLastMessageForChannel($channel);

        $template = $channel->isSubChannel()
            ? 'dashboard/_subchannel_sidebar_item.html.twig'
            : 'dashboard/_channel_sidebar_item.html.twig';

        return $this->render($template, [
            'channel' => $channel,
            'unreadCounts' => $unreadCounts,
            'activeChannel' => null,
            'lastMessages' => $lastMessage ? [$channel->getId() => $lastMessage] : [],
        ]);
    }

    #[Route('/sidebar/filter-channels', name: 'app_sidebar_filter_channels', methods: ['GET'])]
    public function filterChannels(
        Request $request,
        ChannelRepository $channelRepository,
        MessageRepository $messageRepository,
        WorkspaceRepository $workspaceRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $query = trim($request->query->get('q', ''));
        $channels = $channelRepository->findAllForUser($currentUser);
        $workspaces = $workspaceRepository->findAllForUser($currentUser);

        $currentUrl = $request->headers->get('HX-Current-URL');
        $activeChannel = null;
        $currentWorkspace = null;
        if ($currentUrl) {
            $path = parse_url($currentUrl, PHP_URL_PATH);
            if (preg_match('#^/channels/([a-z0-9-]+)$#', $path, $matches)) {
                $activeChannel = $channelRepository->findOneBy(['slug' => $matches[1]]);
                if ($activeChannel && $activeChannel->getWorkspace()) {
                    $currentWorkspace = $activeChannel->getWorkspace();
                }
            }
        }

        $session = $request->getSession();
        if ($currentWorkspace) {
            $session->set('current_workspace_id', $currentWorkspace->getId());
        } else {
            $currentWorkspaceId = $session->get('current_workspace_id');
            if ($currentWorkspaceId) {
                $currentWorkspace = $workspaceRepository->find($currentWorkspaceId);
            }
        }

        if (!$currentWorkspace) {
            $currentWorkspace = $workspaceRepository->findPublicWorkspace();
            if ($currentWorkspace) {
                $session->set('current_workspace_id', $currentWorkspace->getId());
            }
        }

        // Filter channels to current workspace
        if ($currentWorkspace) {
            $channels = array_filter(
                $channels,
                static fn(Channel $c) => (
                    $c->isDm()
                    || $c->getWorkspace()
                    && $c->getWorkspace()->getId() === $currentWorkspace->getId()
                ),
            );
        }

        if ($query !== '') {
            $channels = array_filter(
                $channels,
                static fn(Channel $c) => stripos($c->getName() ?? '', $query) !== false,
            );
        }

        $subChannelsByParent = $this->buildSubChannelsByParent($channels);

        $ucrRepo = $entityManager->getRepository(UserChannelRead::class);
        $unreadCounts = $ucrRepo->getUnreadCounts($currentUser);

        $channelIds = array_map(static fn(Channel $c) => (int) $c->getId(), $channels);
        $lastMessages = $messageRepository->findLastMessagesForChannels($channelIds);

        return $this->render('dashboard/_sidebar_filter_results.html.twig', [
            'channels' => $channels,
            'subChannelsByParent' => $subChannelsByParent,
            'unreadCounts' => $unreadCounts,
            'activeChannel' => $activeChannel,
            'filterMode' => true,
            'lastMessages' => $lastMessages,
            'workspaces' => $workspaces,
        ]);
    }

    private function getChannelTopicUrl(Channel $channel): string
    {
        return $this->mercurePublisher->getChannelTopic($channel);
    }

    /** @param Channel[] $channels */
    private function buildSubChannelsByParent(array $channels): array
    {
        return $this->channelManager->buildSubChannelsByParent($channels);
    }

    /**
     * @param Channel[] $channels
     * @param array<int, array{count: int, hasMention: bool}> $unreadCounts
     * @return array<int, int>
     */
    private function computeWorkspaceUnreadCounts(array $channels, array $unreadCounts): array
    {
        $counts = [];
        foreach ($channels as $ch) {
            $ws = $ch->getWorkspace();
            if (!$ws) {
                continue;
            }
            $wsId = $ws->getId();
            if (!array_key_exists($wsId, $counts)) {
                $counts[$wsId] = 0;
            }
            $counts[$wsId] += $unreadCounts[$ch->getId()]['count'] ?? 0;
        }

        return $counts;
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
