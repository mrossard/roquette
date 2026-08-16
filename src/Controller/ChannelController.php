<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\UserChannelRead;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Repository\WorkspaceRepository;
use App\Service\ChannelManager;
use App\Service\MercurePublisher;
use App\Service\ReadTrackingService;
use App\Service\SidebarDataProvider;
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
    use ChannelAccessTrait;

    public function __construct(
        private readonly MercurePublisher $mercurePublisher,
        private readonly ReadTrackingService $readTrackingService,
        private readonly CacheInterface $cache,
        private readonly TranslatorInterface $translator,
        private readonly ChannelManager $channelManager,
        private readonly SidebarDataProvider $sidebarDataProvider,
    ) {}

    #[Route('/channels/{slug}', name: 'app_channel', requirements: [
        'slug' => '^(?!directory$|reorder$|create$|create-modal$)[^/]+$',
    ])]
    public function channel(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        \App\Service\TypingIndicatorService $typingIndicatorService,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $sidebarData = $this->sidebarDataProvider->getSidebarData($currentUser);
        $channels = $sidebarData['channels'];

        $resolved = $this->resolveActiveChannel($slug, $channels, $currentUser, $channelRepository);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$activeChannel, $isMember] = $resolved;

        if ($activeChannel->getWorkspace()) {
            $request->getSession()->set('current_workspace_id', $activeChannel->getWorkspace()->getId());
        }

        $previousChannelSlug = $request->headers->get('X-Previous-Channel');
        if ($previousChannelSlug && $previousChannelSlug !== $slug) {
            $previousChannel = $channelRepository->findOneBy(['slug' => $previousChannelSlug]);
            if ($previousChannel) {
                $this->readTrackingService->markChannelAsRead($currentUser, $previousChannel);
            }
        }

        $messages = [];
        $firstUnreadMessageId = null;
        if ($isMember) {
            [$messages, $firstUnreadMessageId] = $this->loadChannelMessages(
                $activeChannel,
                $currentUser,
                $request->query->getInt('jumpTo'),
                $entityManager,
                $messageRepository,
            );
        }

        $unreadCounts = $sidebarData['unreadCounts'];
        $notificationsEnabled = null;
        if ($isMember) {
            $activeUnread = $unreadCounts[$activeChannel->getId()] ?? null;
            $notificationsEnabled = $activeUnread['notificationsEnabled'] ?? null;
        }
        if ($notificationsEnabled === null) {
            $notificationsEnabled = $activeChannel->isDm();
        }

        $typingUsers = $this->getTypingUsers($activeChannel, $currentUser, $isMember, $typingIndicatorService);

        $messageIds = array_map(static fn(Message $m) => $m->getId(), $messages);
        $replyCounts = $messageRepository->findReplyCounts($messageIds);
        $subchannelByParentMessageId = $channelRepository->findSubchannelsByChannel($activeChannel);
        $savedMessageIds = $messageRepository->findSavedMessageIdsForUser($currentUser);

        return $this->render('dashboard/index.html.twig', array_merge([
            'activeChannel' => $activeChannel,
            'messages' => $messages,
            'savedMessageIds' => $savedMessageIds,
            'topic_url' => $this->getChannelTopicUrl($activeChannel),
            'firstUnreadMessageId' => $firstUnreadMessageId,
            'usersToInvite' => [],
            'isMember' => $isMember,
            'notificationsEnabled' => $notificationsEnabled,
            'typingUsers' => $typingUsers,
            'replyCounts' => $replyCounts,
            'subchannelByParentMessageId' => $subchannelByParentMessageId,
        ], $sidebarData));
    }

    #[Route('/channels/{slug}/more', name: 'app_channel_load_more', methods: ['GET'])]
    public function loadMore(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        MessageRepository $messageRepository,
    ): Response {
        $activeChannel = $this->channelManager->findChannelBySlug($slug);

        if (!$this->isGranted('VIEW', $activeChannel)) {
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
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $channel = $this->channelManager->findChannelBySlug($slug);

        $this->denyAccessUnlessGranted('VIEW', $channel);

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
        $sidebarData = $this->sidebarDataProvider->getSidebarData($currentUser);
        $channels = $sidebarData['channels'];
        $workspaces = $sidebarData['workspaces'];

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
        if ($currentWorkspace !== null) {
            $session->set('current_workspace_id', $currentWorkspace->getId());
        } else {
            $currentWorkspaceId = $session->get('current_workspace_id');
            if ($currentWorkspaceId !== null) {
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

        $subChannelsByParent = $this->channelManager->buildSubChannelsByParent($channels);

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

    private function getTypingUsers(
        ?Channel $channel,
        User $currentUser,
        bool $isMember,
        \App\Service\TypingIndicatorService $typingIndicatorService,
    ): array {
        if (!$isMember || !$channel) {
            return [];
        }

        return $typingIndicatorService->getTypingUsers($channel, $currentUser);
    }

    /**
     * @param Channel[] $channels
     * @return array{0: Channel, 1: bool}|Response
     */
    private function resolveActiveChannel(
        string $slug,
        array $channels,
        User $currentUser,
        ChannelRepository $channelRepository,
    ): array|Response {
        foreach ($channels as $channel) {
            if ($channel->getSlug() === $slug) {
                return [$channel, true];
            }
        }

        $existingChannel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$existingChannel) {
            throw $this->createNotFoundException($this->translator->trans('Canal non trouvé.'));
        }

        if (!$this->isGranted('VIEW', $existingChannel)) {
            $errorMsg = $existingChannel->isPrivate() && !$existingChannel->getWorkspace()
                ? $this->translator->trans('Vous n\'avez pas accès à ce canal privé.')
                : $this->translator->trans('Vous n\'avez pas accès à ce canal.');
            $this->addFlash('error', $errorMsg);

            return $this->redirectToRoute('app_dashboard');
        }

        $isMember = $existingChannel->getWorkspace() !== null
            ? $this->isGranted('VIEW', $existingChannel->getWorkspace())
            : $existingChannel->getMembers()->contains($currentUser);

        return [$existingChannel, $isMember];
    }

    /**
     * @return array{0: Message[], 1: ?int}
     */
    private function loadChannelMessages(
        Channel $channel,
        User $currentUser,
        int $jumpTo,
        EntityManagerInterface $entityManager,
        MessageRepository $messageRepository,
    ): array {
        $ucrRepo = $entityManager->getRepository(UserChannelRead::class);
        $activeRead = $ucrRepo->findOneBy(['user' => $currentUser, 'channel' => $channel]);
        $lastReadMessageId = $activeRead?->getLastReadMessage()?->getId();

        $messages = $jumpTo > 0
            ? $messageRepository->findMessagesAround($channel, $jumpTo, 50)
            : array_reverse($messageRepository->findLatestInChannel($channel, 50));

        $firstUnreadMessageId = null;
        if ($lastReadMessageId !== null) {
            foreach ($messages as $m) {
                if ($m->getId() <= $lastReadMessageId) {
                    continue;
                }

                $firstUnreadMessageId = $m->getId();
                break;
            }
        }

        $this->readTrackingService->markChannelAsRead($currentUser, $channel);

        return [$messages, $firstUnreadMessageId];
    }
}
