<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ChannelRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
    public function __construct(
        private readonly \App\Service\SidebarDataProvider $sidebarDataProvider,
        private readonly \App\Service\WorkspaceContext $workspaceContext,
    ) {}

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
    public function directory(ChannelRepository $channelRepository, UserRepository $userRepository): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $sidebarData = $this->sidebarDataProvider->getSidebarData($currentUser);
        $currentWorkspace = $this->workspaceContext->getCurrentWorkspaceOrPublic();

        $allPublicChannels = $currentWorkspace ? $channelRepository->findPublicForWorkspace($currentWorkspace) : [];
        $allUsers = $currentWorkspace ? $userRepository->findMembersForWorkspace($currentWorkspace, $currentUser) : [];

        return $this->render('dashboard/directory.html.twig', array_merge([
            'allPublicChannels' => $allPublicChannels,
            'activeChannel' => null,
            'allUsers' => $allUsers,
            'currentWorkspace' => $currentWorkspace,
        ], $sidebarData));
    }

    #[Route('/channels/directory/panel/{type}', name: 'app_directory_panel')]
    public function directoryPanel(
        string $type,
        Request $request,
        ChannelRepository $channelRepository,
        UserRepository $userRepository,
    ): Response {
        $currentUser = $this->getUser();
        $currentWorkspace = $this->workspaceContext->getCurrentWorkspaceOrPublic();

        $etag = md5(sprintf(
            'directory-%s-%s-%s',
            $type,
            $currentWorkspace?->getId() ?? 'none',
            $currentUser?->getUserIdentifier() ?? 'guest',
        ));
        $response = new Response();
        $response->setEtag($etag);
        $response->setPublic();

        if ($response->isNotModified($request)) {
            return $response;
        }

        if ($type === 'members') {
            $allUsers = $currentWorkspace
                ? $userRepository->findMembersForWorkspace($currentWorkspace, $currentUser)
                : [];

            return $this->render(
                'dashboard/_directory_panel.html.twig',
                [
                    'type' => 'members',
                    'allUsers' => $allUsers,
                ],
                $response,
            );
        }

        $allPublicChannels = $currentWorkspace ? $channelRepository->findPublicForWorkspace($currentWorkspace) : [];

        return $this->render(
            'dashboard/_directory_panel.html.twig',
            [
                'type' => 'channels',
                'allPublicChannels' => $allPublicChannels,
            ],
            $response,
        );
    }
}
