<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Workspace;
use App\Repository\WorkspaceRepository;
use App\Service\WorkspaceManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMIN')]
final class AdminWorkspaceController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/admin/workspaces', name: 'app_admin_workspaces')]
    public function workspaces(WorkspaceRepository $workspaceRepo): Response
    {
        return $this->render('admin/workspaces.html.twig', [
            'workspaces' => $workspaceRepo->findBy([], ['name' => 'ASC']),
            'currentRoute' => 'app_admin_workspaces',
        ]);
    }

    #[Route('/admin/workspaces/delete/{id}', name: 'app_admin_workspace_delete', methods: ['POST'])]
    public function deleteWorkspace(
        Request $request,
        Workspace $workspace,
        WorkspaceManager $workspaceManager,
    ): Response {
        if (!$this->isCsrfTokenValid('delete-workspace-' . $workspace->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('Token CSRF invalide.'));

            return $this->redirectToRoute('app_admin_workspaces');
        }

        if ($workspace->isPublic()) {
            $this->addFlash('error', $this->translator->trans('Le workspace public ne peut pas être supprimé.'));

            return $this->redirectToRoute('app_admin_workspaces');
        }

        $workspaceManager->delete($workspace, $this->getUser());
        $this->addFlash('success', $this->translator->trans('Workspace supprimé avec succès.'));

        return $this->redirectToRoute('app_admin_workspaces');
    }
}
