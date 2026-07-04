<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Channel;
use App\Entity\User;
use App\Entity\UserChannelRead;
use App\Entity\Workspace;
use App\Repository\ChannelRepository;
use App\Repository\InvitationRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use App\Service\ReadTrackingService;
use App\Service\WorkspaceManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Handles the top-level dashboard routes only:
 *   - redirect to the user's first channel
 *   - channel directory listing
 *
 * All other dashboard functionality has been split into dedicated controllers:
 *   - ChannelController      — CRUD and navigation for channels
 *   - MessageController      — send, edit, delete messages
 *   - ReactionController     — emoji reactions
 *   - FileController         — file download and preview
 *   - InvitationController   — invite, accept, reject
 *   - NotificationController — read state, unread feed, search, typing
 *   - UserSettingsController — color, status, pin, API
 */
#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    // -------------------------------------------------------------------------
    // Root redirect
    // -------------------------------------------------------------------------

    #[Route('/', name: 'app_dashboard')]
    public function index(ChannelRepository $channelRepository, WorkspaceRepository $workspaceRepository): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channels = $channelRepository->findAllForUser($currentUser);
        if ($channels === []) {
            return $this->redirectToRoute('app_channels_directory');
        }

        // Redirect to the general channel in the public workspace
        $publicWorkspace = $workspaceRepository->findPublicWorkspace();
        if ($publicWorkspace) {
            $general = $channelRepository->findOneBy([
                'workspace' => $publicWorkspace,
                'slug' => 'general',
            ]);
            if ($general) {
                return $this->redirectToRoute('app_channel', ['slug' => $general->getSlug()]);
            }
        }

        // Fallback: find any general channel
        $general = $channelRepository->findOneBy(['slug' => 'general']);
        if ($general !== null) {
            return $this->redirectToRoute('app_channel', ['slug' => 'general']);
        }

        return $this->redirectToRoute('app_channel', ['slug' => $channels[0]->getSlug()]);
    }

    // -------------------------------------------------------------------------
    // Channel directory
    // -------------------------------------------------------------------------

    #[Route('/channels/directory', name: 'app_channels_directory')]
    public function directory(
        ChannelRepository $channelRepository,
        MessageRepository $messageRepository,
        UserRepository $userRepository,
        InvitationRepository $invitationRepository,
        WorkspaceRepository $workspaceRepository,
        WorkspaceManager $workspaceManager,
        EntityManagerInterface $entityManager,
        ReadTrackingService $readTrackingService,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channels = $channelRepository->findAllForUser($currentUser);

        $readTrackingService->ensureUserChannelReads($currentUser, $channels);

        $ucrRepo = $entityManager->getRepository(UserChannelRead::class);
        $unreadCounts = $ucrRepo->getUnreadCounts($currentUser);

        // Aggregate unread counts per workspace
        $workspaceUnreadCounts = [];
        foreach ($channels as $ch) {
            $ws = $ch->getWorkspace();
            if (!$ws) {
                continue;
            }
            $wsId = $ws->getId();
            if (!array_key_exists($wsId, $workspaceUnreadCounts)) {
                $workspaceUnreadCounts[$wsId] = 0;
            }
            $workspaceUnreadCounts[$wsId] += $unreadCounts[$ch->getId()]['count'] ?? 0;
        }

        $pendingInvitations = $invitationRepository->findPendingForUser($currentUser);
        $allPublicChannels = $channelRepository->findAllPublic();
        $workspaces = $workspaceRepository->findAllForUser($currentUser);
        $allUsers = array_filter(
            $userRepository->findAllExcept($currentUser),
            static fn(User $u) => $u->getUsername() !== User::ROBOT_USERNAME,
        );

        $subChannelsByParent = [];
        foreach ($channels as $ch) {
            if (!($ch->isSubChannel() && $ch->getParentMessage())) {
                continue;
            }

            $parentId = $ch->getParentMessage()->getChannel()->getId();
            $subChannelsByParent[$parentId][] = $ch;
        }

        $lastMessages = $messageRepository->findLastMessagesForChannels(array_map(
            static fn(Channel $c) => $c->getId(),
            $channels,
        ));

        return $this->render('dashboard/directory.html.twig', [
            'channels' => $channels,
            'allPublicChannels' => $allPublicChannels,
            'unreadCounts' => $unreadCounts,
            'pendingInvitations' => $pendingInvitations,
            'activeChannel' => null,
            'allUsers' => $allUsers,
            'subChannelsByParent' => $subChannelsByParent,
            'lastMessages' => $lastMessages,
            'workspaces' => $workspaces,
            'workspaceUnreadCounts' => $workspaceUnreadCounts,
        ]);
    }

    #[Route('/channels/directory/panel/{type}', name: 'app_directory_panel')]
    public function directoryPanel(
        string $type,
        ChannelRepository $channelRepository,
        UserRepository $userRepository,
    ): Response {
        $currentUser = $this->getUser();

        if ($type === 'members') {
            $allUsers = array_filter(
                $userRepository->findAllExcept($currentUser),
                static fn(User $u) => $u->getUsername() !== User::ROBOT_USERNAME,
            );

            return $this->render('dashboard/_directory_panel.html.twig', [
                'type' => 'members',
                'allUsers' => $allUsers,
            ]);
        }

        return $this->render('dashboard/_directory_panel.html.twig', [
            'type' => 'channels',
            'allPublicChannels' => $channelRepository->findAllPublic(),
        ]);
    }
}
