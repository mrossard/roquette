<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\WorkspaceRepository;
use App\Service\WorkspaceManager;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly WorkspaceManager $workspaceManager,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/workspaces/{slug}/invite-modal', name: 'app_workspace_invite_modal', methods: ['GET'])]
    public function inviteModal(string $slug, WorkspaceRepository $workspaceRepository): Response
    {
        $workspace = $workspaceRepository->findOneBy(['slug' => $slug]);
        if (!$workspace) {
            return new Response($this->translator->trans('Espace non trouvé.'), 404);
        }

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
        WorkspaceRepository $workspaceRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $workspaceRepository->findOneBy(['slug' => $slug]);
        if (!$workspace) {
            return new Response($this->translator->trans('Espace non trouvé.'), 404);
        }

        $this->denyAccessUnlessGranted('INVITE', $workspace);

        $userId = $request->request->get('userId');
        if (!$userId) {
            return new Response($this->translator->trans('ID utilisateur manquant.'), 400);
        }

        $invitedUser = $entityManager->getRepository(User::class)->find($userId);
        if (!$invitedUser) {
            return new Response($this->translator->trans('Utilisateur non trouvé.'), 404);
        }

        $this->workspaceManager->inviteUser($workspace, $currentUser, $invitedUser);

        $query = trim((string) $request->request->get('q', ''));
        $usersToInvite = [];
        if ($query !== '') {
            $usersToInvite = $workspaceRepository->findMembersNotInWorkspace($workspace, $currentUser, $query);
        }

        return $this->render('modals/_workspace_invite_results.html.twig', [
            'workspace' => $workspace,
            'usersToInvite' => $usersToInvite,
            'successMessage' => sprintf('%s a été invité !', $invitedUser->getUsername()),
            'searched' => $query !== '',
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

        $this->denyAccessUnlessGranted('INVITE', $workspace);

        $query = trim((string) $request->query->get('q', ''));
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
}
