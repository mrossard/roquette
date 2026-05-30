<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\UserChannelRead;
use App\Repository\ChannelRepository;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Service\ReadTrackingService;
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
 *   - ThreadController       — thread replies
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
    public function index(ChannelRepository $channelRepository): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channels = $channelRepository->findAllForUser($currentUser);
        if (empty($channels)) {
            return $this->redirectToRoute('app_channels_directory');
        }

        return $this->redirectToRoute('app_channel', ['slug' => $channels[0]->getSlug()]);
    }

    // -------------------------------------------------------------------------
    // Channel directory
    // -------------------------------------------------------------------------

    #[Route('/channels/directory', name: 'app_channels_directory')]
    public function directory(
        ChannelRepository $channelRepository,
        UserRepository $userRepository,
        InvitationRepository $invitationRepository,
        EntityManagerInterface $entityManager,
        ReadTrackingService $readTrackingService,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channels = $channelRepository->findAllForUser($currentUser);

        $readTrackingService->ensureUserChannelReads($currentUser, $channels);

        $ucrRepo = $entityManager->getRepository(UserChannelRead::class);
        $unreadCounts = $ucrRepo->getUnreadCounts($currentUser);

        $pendingInvitations = $invitationRepository->findPendingForUser($currentUser);
        $allPublicChannels = $channelRepository->findAllPublic();
        $allUsers = $userRepository->findAllExcept($currentUser);

        return $this->render('dashboard/directory.html.twig', [
            'channels' => $channels,
            'allPublicChannels' => $allPublicChannels,
            'unreadCounts' => $unreadCounts,
            'pendingInvitations' => $pendingInvitations,
            'activeChannel' => null,
            'allUsers' => $allUsers,
        ]);
    }
}
