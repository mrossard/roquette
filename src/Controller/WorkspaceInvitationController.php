<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\InvitationManager;
use App\Service\WorkspaceManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class WorkspaceInvitationController extends AbstractController
{
    public function __construct(
        private readonly InvitationManager $invitationManager,
        private readonly WorkspaceManager $workspaceManager,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/workspaces/{slug}/invite-modal', name: 'app_workspace_invite_modal', methods: ['GET'])]
    public function inviteModal(string $slug): Response
    {
        $workspace = $this->workspaceManager->findWorkspaceBySlug($slug);

        $this->denyAccessUnlessGranted('INVITE', $workspace);

        return $this->render('modals/_workspace_invite_modal.html.twig', [
            'workspace' => $workspace,
            'usersToInvite' => [],
        ]);
    }

    #[Route('/workspaces/{slug}/invite', name: 'app_workspace_invite', methods: ['POST'])]
    public function invite(
        string $slug,
        Request $request,
        UserRepository $userRepository,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $this->workspaceManager->findWorkspaceBySlug($slug);

        $this->denyAccessUnlessGranted('INVITE', $workspace);

        $userId = $request->request->getInt('userId');
        if ($userId <= 0) {
            return new Response($this->translator->trans('ID utilisateur manquant.'), 400);
        }

        $invitedUser = $userRepository->find($userId);
        if (!$invitedUser) {
            return new Response($this->translator->trans('Utilisateur non trouvé.'), 404);
        }

        $this->invitationManager->inviteToWorkspace($workspace, $currentUser, $invitedUser);

        $query = trim((string) $request->request->get('q', ''));
        $usersToInvite = $this->invitationManager->searchInvitableUsersForWorkspace($workspace, $currentUser, $query);

        return $this->render('modals/_workspace_invite_results.html.twig', [
            'workspace' => $workspace,
            'usersToInvite' => $usersToInvite,
            'successMessage' => sprintf('%s a été invité !', $invitedUser->getUsername()),
            'searched' => $query !== '',
        ]);
    }

    #[Route('/workspaces/{slug}/invite/search', name: 'app_workspace_invite_search', methods: ['GET'])]
    public function searchInvitableUsers(string $slug, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $this->workspaceManager->findWorkspaceBySlug($slug);

        $this->denyAccessUnlessGranted('INVITE', $workspace);

        $query = trim((string) $request->query->get('q', ''));
        if ($query === '') {
            return $this->render('modals/_workspace_invite_results.html.twig', [
                'workspace' => $workspace,
                'usersToInvite' => [],
                'searched' => false,
            ]);
        }

        $usersToInvite = $this->invitationManager->searchInvitableUsersForWorkspace($workspace, $currentUser, $query);

        return $this->render('modals/_workspace_invite_results.html.twig', [
            'workspace' => $workspace,
            'usersToInvite' => $usersToInvite,
            'searched' => true,
        ]);
    }
}
