<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\ChannelRepository;
use App\Repository\InvitationRepository;
use App\Repository\MessageRepository;
use App\Repository\WorkspaceRepository;
use App\Service\ReadTrackingService;
use App\Service\WorkspaceManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class WorkspaceController extends AbstractController
{
    public function __construct(
        private readonly WorkspaceManager $workspaceManager,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/workspaces', name: 'app_workspaces')]
    public function index(
        WorkspaceRepository $workspaceRepository,
        ChannelRepository $channelRepository,
        InvitationRepository $invitationRepository,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspaces = $workspaceRepository->findAllForUser($currentUser);
        $pendingInvitations = $invitationRepository->findPendingForUser($currentUser);

        $workspaceChannels = [];
        foreach ($workspaces as $ws) {
            $workspaceChannels[$ws->getId()] = $this->workspaceManager->getChannelsForUser($ws, $currentUser);
        }

        return $this->render('workspace/index.html.twig', [
            'workspaces' => $workspaces,
            'workspaceChannels' => $workspaceChannels,
            'pendingInvitations' => $pendingInvitations,
        ]);
    }

    #[Route('/w/{workspaceSlug}', name: 'app_workspace_switch')]
    public function switchWorkspace(
        string $workspaceSlug,
        Request $request,
        WorkspaceRepository $workspaceRepository,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $workspaceRepository->findOneBy(['slug' => $workspaceSlug]);
        if (!$workspace) {
            throw $this->createNotFoundException($this->translator->trans('Espace non trouvé.'));
        }

        if (!$workspace->isMember($currentUser) && !$workspace->isPublic()) {
            throw $this->createAccessDeniedException();
        }

        // If public workspace, ensure user is member
        if ($workspace->isPublic() && !$workspace->isMember($currentUser)) {
            $workspace->addMember($currentUser);
            $entityManager->flush();
        }

        $request->getSession()->set('current_workspace_id', $workspace->getId());

        // Redirect to default channel in this workspace
        $defaultChannel = $this->workspaceManager->getDefaultChannel($workspace);
        if ($defaultChannel) {
            return $this->redirectToRoute('app_channel', [
                'slug' => $defaultChannel->getSlug(),
            ]);
        }

        return $this->redirectToRoute('app_workspaces');
    }

    #[Route('/workspaces/create-modal', name: 'app_workspace_create_modal', methods: ['GET'])]
    public function createModal(): Response
    {
        return $this->render('modals/_create_workspace_modal.html.twig');
    }

    #[Route('/workspaces/create', name: 'app_workspace_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $name = trim($request->request->get('name', ''));
        $description = trim($request->request->get('description', ''));

        if ($name === '') {
            $this->addFlash('error', $this->translator->trans('Le nom du workspace ne peut pas être vide.'));

            return $this->redirectToRoute('app_workspaces');
        }

        $workspace = $this->workspaceManager->create($name, $description ?: null, $currentUser);
        $request->getSession()->set('current_workspace_id', $workspace->getId());

        $defaultChannel = $this->workspaceManager->getDefaultChannel($workspace);
        if ($defaultChannel) {
            return $this->redirectToRoute('app_channel', ['slug' => $defaultChannel->getSlug()]);
        }

        return $this->redirectToRoute('app_workspaces');
    }

    #[Route('/workspaces/{slug}/settings-modal', name: 'app_workspace_settings_modal', methods: ['GET'])]
    public function settingsModal(string $slug, WorkspaceRepository $workspaceRepository): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $workspaceRepository->findOneBy(['slug' => $slug]);
        if (!$workspace) {
            return new Response($this->translator->trans('Espace non trouvé.'), 404);
        }

        if ($workspace->getCreator() !== $currentUser && !$this->isGranted('ROLE_ADMIN')) {
            return new Response($this->translator->trans('Accès refusé.'), 403);
        }

        return $this->render('modals/_workspace_settings_modal.html.twig', [
            'workspace' => $workspace,
        ]);
    }

    #[Route('/workspaces/{slug}/edit', name: 'app_workspace_edit', methods: ['POST'])]
    public function edit(string $slug, Request $request, WorkspaceRepository $workspaceRepository): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $workspaceRepository->findOneBy(['slug' => $slug]);
        if (!$workspace) {
            return $this->redirectToRoute('app_workspaces');
        }

        if ($workspace->getCreator() !== $currentUser && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $name = trim($request->request->get('name', ''));
        $description = trim($request->request->get('description', ''));

        if ($name === '') {
            $this->addFlash('error', $this->translator->trans('Le nom du workspace ne peut pas être vide.'));

            return $this->redirectToRoute('app_workspace_switch', ['workspaceSlug' => $slug]);
        }

        $this->workspaceManager->update($workspace, $name, $description ?: null);

        $this->addFlash('success', $this->translator->trans('Les paramètres du workspace ont été modifiés.'));

        return $this->redirectToRoute('app_workspace_switch', ['workspaceSlug' => $workspace->getSlug()]);
    }

    #[Route('/workspaces/{slug}/delete', name: 'app_workspace_delete', methods: ['POST'])]
    public function delete(string $slug, WorkspaceRepository $workspaceRepository): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $workspaceRepository->findOneBy(['slug' => $slug]);
        if (!$workspace) {
            return $this->redirectToRoute('app_workspaces');
        }

        if ($workspace->getCreator() !== $currentUser && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->workspaceManager->delete($workspace, $currentUser);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_workspaces');
        }

        $this->addFlash('success', $this->translator->trans('Le workspace "%name%" a été supprimé.', [
            '%name%' => $workspace->getName(),
        ]));

        return $this->redirectToRoute('app_workspace_switch', ['workspaceSlug' => 'public']);
    }

    #[Route('/workspaces/{slug}/invite-modal', name: 'app_workspace_invite_modal', methods: ['GET'])]
    public function inviteModal(string $slug, WorkspaceRepository $workspaceRepository): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $workspaceRepository->findOneBy(['slug' => $slug]);
        if (!$workspace) {
            return new Response($this->translator->trans('Espace non trouvé.'), 404);
        }

        if ($workspace->getCreator() !== $currentUser && !$this->isGranted('ROLE_ADMIN')) {
            return new Response($this->translator->trans('Accès refusé.'), 403);
        }

        return $this->render('modals/_workspace_invite_modal.html.twig', [
            'workspace' => $workspace,
            'usersToInvite' => [],
        ]);
    }

    #[Route('/workspaces/{slug}/invite', name: 'app_workspace_invite', methods: ['POST'])]
    public function invite(
        string $slug,
        Request $request,
        WorkspaceRepository $workspaceRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $workspaceRepository->findOneBy(['slug' => $slug]);
        if (!$workspace) {
            return new Response($this->translator->trans('Espace non trouvé.'), 404);
        }

        if ($workspace->getCreator() !== $currentUser && !$this->isGranted('ROLE_ADMIN')) {
            return new Response($this->translator->trans('Accès refusé.'), 403);
        }

        $userId = $request->request->get('userId');
        if (!$userId) {
            return new Response($this->translator->trans('ID utilisateur manquant.'), 400);
        }

        $invitedUser = $entityManager->getRepository(User::class)->find($userId);
        if (!$invitedUser) {
            return new Response($this->translator->trans('Utilisateur non trouvé.'), 404);
        }

        $invitation = $this->workspaceManager->inviteUser($workspace, $currentUser, $invitedUser);

        return $this->render('modals/_workspace_invite_results.html.twig', [
            'workspace' => $workspace,
            'successMessage' => sprintf('%s a été invité !', $invitedUser->getUsername()),
        ]);
    }

    #[Route('/workspaces/{slug}/invite/search', name: 'app_workspace_invite_search', methods: ['GET'])]
    public function searchInvitableUsers(
        string $slug,
        Request $request,
        WorkspaceRepository $workspaceRepository,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $workspaceRepository->findOneBy(['slug' => $slug]);
        if (!$workspace) {
            return new Response($this->translator->trans('Espace non trouvé.'), 404);
        }

        $query = trim($request->query->get('q', ''));

        if ($query === '') {
            return $this->render('modals/_workspace_invite_results.html.twig', [
                'workspace' => $workspace,
                'usersToInvite' => [],
                'searched' => false,
            ]);
        }

        $usersToInvite = $workspaceRepository->findMembersNotInWorkspace($workspace, $currentUser, $query);

        return $this->render('modals/_workspace_invite_results.html.twig', [
            'workspace' => $workspace,
            'usersToInvite' => $usersToInvite,
            'searched' => true,
        ]);
    }

    #[Route('/workspaces/{slug}/leave', name: 'app_workspace_leave', methods: ['POST'])]
    public function leave(string $slug, WorkspaceRepository $workspaceRepository): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $workspaceRepository->findOneBy(['slug' => $slug]);
        if (!$workspace) {
            return $this->redirectToRoute('app_workspaces');
        }

        if ($workspace->isPublic()) {
            $this->addFlash('error', $this->translator->trans('Impossible de quitter le workspace public.'));

            return $this->redirectToRoute('app_workspace_switch', ['workspaceSlug' => 'public']);
        }

        $this->workspaceManager->removeMember($workspace, $currentUser);

        return $this->redirectToRoute('app_workspace_switch', ['workspaceSlug' => 'public']);
    }

    #[Route('/workspaces/{slug}/members-modal', name: 'app_workspace_members_modal', methods: ['GET'])]
    public function membersModal(string $slug, WorkspaceRepository $workspaceRepository): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $workspaceRepository->findOneBy(['slug' => $slug]);
        if (!$workspace) {
            return new Response($this->translator->trans('Espace non trouvé.'), 404);
        }

        if (!$workspace->isMember($currentUser)) {
            return new Response($this->translator->trans('Accès refusé.'), 403);
        }

        return $this->render('modals/_workspace_members_modal.html.twig', [
            'workspace' => $workspace,
        ]);
    }
}
