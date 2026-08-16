<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\InvitationRepository;
use App\Repository\WorkspaceRepository;
use App\Service\WorkspaceManager;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

        $this->denyAccessUnlessGranted('VIEW', $workspace);

        // If public workspace, ensure user is member
        if ($workspace->isPublic() && !$this->workspaceManager->isUserMember($workspace, $currentUser)) {
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

        $workspace = $this->workspaceManager->create($name, $description !== '' ? $description : null, $currentUser);
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

        $this->denyAccessUnlessGranted('EDIT', $workspace);

        return $this->render('modals/_workspace_settings_modal.html.twig', [
            'workspace' => $workspace,
        ]);
    }

    #[Route('/workspaces/{slug}/edit', name: 'app_workspace_edit', methods: ['POST'])]
    public function edit(
        string $slug,
        Request $request,
        WorkspaceRepository $workspaceRepository,
        EntityManagerInterface $entityManager,
        FileUploadService $fileUploadService,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $workspaceRepository->findOneBy(['slug' => $slug]);
        if (!$workspace) {
            return $this->redirectToRoute('app_workspaces');
        }

        $this->denyAccessUnlessGranted('EDIT', $workspace);

        $name = trim($request->request->get('name', ''));
        $description = trim($request->request->get('description', ''));

        if ($name === '') {
            $this->addFlash('error', $this->translator->trans('Le nom du workspace ne peut pas être vide.'));

            return $this->redirectToRoute('app_workspace_switch', ['workspaceSlug' => $slug]);
        }

        // Delete avatar if requested
        if ($request->request->has('delete_avatar')) {
            if ($workspace->getAvatarPath()) {
                $fileUploadService->delete($workspace->getAvatarPath());
                $workspace->setAvatarPath(null);
            }
        }

        // Handle file upload
        /** @var UploadedFile|null $avatarFile */
        $avatarFile = $request->files->get('avatar');
        if ($avatarFile instanceof UploadedFile) {
            try {
                // Delete old avatar first if it exists
                if ($workspace->getAvatarPath()) {
                    $fileUploadService->delete($workspace->getAvatarPath());
                }
                $meta = $fileUploadService->upload($avatarFile);
                $workspace->setAvatarPath($meta->filePath);
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('app_workspace_switch', ['workspaceSlug' => $slug]);
            }
        }

        $this->workspaceManager->update($workspace, $name, $description !== '' ? $description : null);
        $entityManager->flush();

        $this->addFlash('success', $this->translator->trans('Les paramètres du workspace ont été modifiés.'));

        return $this->redirectToRoute('app_workspace_switch', ['workspaceSlug' => $workspace->getSlug()]);
    }

    #[Route('/workspaces/{slug}/delete', name: 'app_workspace_delete', methods: ['POST'])]
    public function delete(
        string $slug,
        WorkspaceRepository $workspaceRepository,
        FileUploadService $fileUploadService,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $workspaceRepository->findOneBy(['slug' => $slug]);
        if (!$workspace) {
            return $this->redirectToRoute('app_workspaces');
        }

        $this->denyAccessUnlessGranted('DELETE', $workspace);

        if ($workspace->getAvatarPath()) {
            $fileUploadService->delete($workspace->getAvatarPath());
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

    #[Route('/workspaces/{slug}/avatar', name: 'app_workspace_avatar', methods: ['GET'])]
    public function serveAvatar(
        string $slug,
        WorkspaceRepository $workspaceRepository,
        FileUploadService $fileUploadService,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspace = $workspaceRepository->findOneBy(['slug' => $slug]);
        if (!$workspace || !$workspace->getAvatarPath()) {
            throw $this->createNotFoundException($this->translator->trans('Avatar non trouvé.'));
        }

        $this->denyAccessUnlessGranted('VIEW', $workspace);

        if (!$fileUploadService->exists($workspace->getAvatarPath())) {
            throw $this->createNotFoundException($this->translator->trans('Le fichier n\'existe pas.'));
        }

        $stream = $fileUploadService->readStream($workspace->getAvatarPath());

        $ext = strtolower(pathinfo($workspace->getAvatarPath(), PATHINFO_EXTENSION));
        $mimeType = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };

        return new StreamedResponse(
            static function () use ($stream) {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            Response::HTTP_OK,
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'Content-Security-Policy' => 'sandbox',
            ]
        );
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

        $invitation = $this->workspaceManager->inviteUser($workspace, $currentUser, $invitedUser);

        $query = $request->request->get('q', '');
        $query = trim($query);

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
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspaces = $workspaceRepository->findAllForUser($currentUser);
        $channels = $channelRepository->findAllForUser($currentUser);

        $ucrRepo = $entityManager->getRepository(\App\Entity\UserChannelRead::class);
        $unreadCounts = $ucrRepo->getUnreadCounts($currentUser);

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
