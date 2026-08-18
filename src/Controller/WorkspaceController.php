<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Workspace\CreateWorkspaceDto;
use App\Dto\Workspace\UpdateWorkspaceDto;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\InvitationRepository;
use App\Repository\UserChannelReadRepository;
use App\Repository\WorkspaceRepository;
use App\Service\WorkspaceManager;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        private readonly \App\Service\WorkspaceContext $workspaceContext,
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
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $this->workspaceManager->findWorkspaceBySlug($workspaceSlug);

        $this->denyAccessUnlessGranted('VIEW', $workspace);

        // If public workspace, ensure user is member
        if ($workspace->isPublic() && !$this->workspaceManager->isUserMember($workspace, $currentUser)) {
            $workspace->addMember($currentUser);
            $entityManager->flush();
        }

        $this->workspaceContext->setCurrentWorkspace($workspace);

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

        $dto = CreateWorkspaceDto::fromRequest($request);
        if (!$dto->isValid()) {
            $this->addFlash('error', $this->translator->trans('Le nom du workspace ne peut pas être vide.'));

            return $this->redirectToRoute('app_workspaces');
        }

        $workspace = $this->workspaceManager->create($dto->name, $dto->description, $currentUser);
        $this->workspaceContext->setCurrentWorkspace($workspace);

        $defaultChannel = $this->workspaceManager->getDefaultChannel($workspace);
        if ($defaultChannel) {
            return $this->redirectToRoute('app_channel', ['slug' => $defaultChannel->getSlug()]);
        }

        return $this->redirectToRoute('app_workspaces');
    }

    #[Route('/workspaces/{slug}/settings-modal', name: 'app_workspace_settings_modal', methods: ['GET'])]
    public function settingsModal(string $slug): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $this->workspaceManager->findWorkspaceBySlug($slug);

        $this->denyAccessUnlessGranted('EDIT', $workspace);

        return $this->render('modals/_workspace_settings_modal.html.twig', [
            'workspace' => $workspace,
        ]);
    }

    #[Route('/workspaces/{slug}/edit', name: 'app_workspace_edit', methods: ['POST'])]
    public function edit(string $slug, Request $request): Response
    {
        $workspace = $this->workspaceManager->findWorkspaceBySlugOrNull($slug);
        if (!$workspace) {
            return $this->redirectToRoute('app_workspaces');
        }

        $this->denyAccessUnlessGranted('EDIT', $workspace);

        $dto = UpdateWorkspaceDto::fromRequest($request);
        if (!$dto->isValid()) {
            $this->addFlash('error', $this->translator->trans('Le nom du workspace ne peut pas être vide.'));

            return $this->redirectToRoute('app_workspace_switch', ['workspaceSlug' => $slug]);
        }

        try {
            $this->workspaceManager->update($workspace, $dto);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_workspace_switch', ['workspaceSlug' => $slug]);
        }

        $this->addFlash('success', $this->translator->trans('Les paramètres du workspace ont été modifiés.'));

        return $this->redirectToRoute('app_workspace_switch', ['workspaceSlug' => $workspace->getSlug()]);
    }

    #[Route('/workspaces/{slug}/delete', name: 'app_workspace_delete', methods: ['POST'])]
    public function delete(string $slug): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $this->workspaceManager->findWorkspaceBySlugOrNull($slug);
        if (!$workspace) {
            return $this->redirectToRoute('app_workspaces');
        }

        $this->denyAccessUnlessGranted('DELETE', $workspace);

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

    #[Route('/workspaces/{slug}/avatar', name: 'app_workspace_avatar', methods: ['GET'])]
    public function serveAvatar(
        string $slug,
        FileUploadService $fileUploadService,
        \App\Service\FileStreamResponseFactory $fileResponseFactory,
    ): Response {
        $workspace = $this->workspaceManager->findWorkspaceBySlugOrNull($slug);
        if (!$workspace || !$workspace->getAvatarPath()) {
            throw $this->createNotFoundException($this->translator->trans('Avatar non trouvé.'));
        }

        $this->denyAccessUnlessGranted('VIEW', $workspace);

        return $fileResponseFactory->createAvatarResponse($workspace->getAvatarPath(), $fileUploadService);
    }


    #[Route('/workspaces/{slug}/leave', name: 'app_workspace_leave', methods: ['POST'])]
    public function leave(string $slug): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $this->workspaceManager->findWorkspaceBySlugOrNull($slug);
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
    public function membersModal(string $slug): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $this->workspaceManager->findWorkspaceBySlug($slug);

        $this->denyAccessUnlessGranted('VIEW', $workspace);

        return $this->render('modals/_workspace_members_modal.html.twig', [
            'workspace' => $workspace,
        ]);
    }

    #[Route('/sidebar/workspace-selector', name: 'app_sidebar_workspace_selector', methods: ['GET'])]
    public function workspaceSelector(
        Request $request,
        ChannelRepository $channelRepository,
        WorkspaceRepository $workspaceRepository,
        UserChannelReadRepository $userChannelReadRepository,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspaces = $workspaceRepository->findAllForUser($currentUser);
        $workspaceUnreadCounts = $userChannelReadRepository->getUnreadCountsByWorkspace($currentUser);

        $activeChannel = null;
        $channelSlug = $request->query->get('channel');
        if ($channelSlug) {
            $activeChannel = $channelRepository->findOneBy(['slug' => $channelSlug]);
        }

        return $this->render('dashboard/_sidebar_workspace_selector.html.twig', [
            'workspaces' => $workspaces,
            'workspaceUnreadCounts' => $workspaceUnreadCounts,
            'activeChannel' => $activeChannel,
        ]);
    }
}
