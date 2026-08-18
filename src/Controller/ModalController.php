<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Repository\WorkspaceRepository;
use App\Service\ChannelEditModalDataProvider;
use App\Service\ChannelManager;
use App\Service\ChannelMembersDataProvider;
use App\Service\Group\GroupProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ModalController extends AbstractController
{
    use ChannelAccessTrait;

    public function __construct(
        private readonly GroupProviderInterface $groupProvider,
    ) {}

    #[Route('/channels/{slug}/edit-modal', name: 'app_channel_edit_modal', methods: ['GET'])]
    public function editModal(
        string $slug,
        ChannelManager $channelManager,
        ChannelEditModalDataProvider $modalDataProvider,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $channel = $this->findAuthorizedChannel($slug, $channelManager, 'EDIT');

        return $this->render('modals/_edit_channel_modal.html.twig', $modalDataProvider->getEditModalData($channel));
    }

    #[Route('/channels/{slug}/invite-modal', name: 'app_channel_invite_modal', methods: ['GET'])]
    public function inviteModal(string $slug, ChannelManager $channelManager): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $channel = $channelManager->findChannelBySlug($slug);

        if ($channel->isDm() || $channel->isSubChannel()) {
            return new Response('Accès refusé', 403);
        }

        $workspace = $channel->getWorkspace();
        $isWorkspaceDenied = $workspace !== null && $workspace->getCreator() !== $currentUser;
        $isChannelDenied = $workspace === null && (!$channel->isPrivate() || $channel->getCreator() !== $currentUser);

        if ($isWorkspaceDenied || $isChannelDenied) {
            return new Response('Accès refusé', 403);
        }

        return $this->render('modals/_invite_member_modal.html.twig', [
            'activeChannel' => $channel,
            'usersToInvite' => [], // Starting empty, search is done via AJAX
        ]);
    }

    #[Route('/channels/{slug}/members-modal', name: 'app_channel_members_modal', methods: ['GET'])]
    public function membersModal(
        string $slug,
        ChannelManager $channelManager,
        ChannelMembersDataProvider $membersDataProvider,
    ): Response {
        $channel = $channelManager->findChannelBySlug($slug);

        $this->authorizeChannelAccess($channel);

        return $this->render(
            'modals/_channel_members_modal.html.twig',
            $membersDataProvider->getMembersModalData($channel),
        );
    }

    #[Route('/channels/create-modal', name: 'app_channel_create_modal', methods: ['GET'])]
    public function createModal(
        Request $request,
        WorkspaceRepository $workspaceRepository,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $groups = $this->groupProvider->getGroups();
        $workspaces = $workspaceRepository->findAllForUser($currentUser);

        return $this->render('modals/_create_channel_modal.html.twig', [
            'defaultTodo' => $request->query->getBoolean('defaultTodo', false),
            'groups' => $groups,
            'workspaces' => $workspaces,
            'activeWorkspace' => null,
        ]);
    }
}
