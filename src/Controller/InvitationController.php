<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\HxControllerTrait;
use App\Entity\User;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Service\ChannelManager;
use App\Service\InvitationManager;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class InvitationController extends AbstractController
{
    use HxControllerTrait;

    public function __construct(
        private readonly InvitationManager $invitationManager,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/channels/{slug}/invite', name: 'app_channel_invite', methods: ['POST'])]
    public function inviteUser(
        string $slug,
        Request $request,
        ChannelManager $channelManager,
        UserRepository $userRepository,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $activeChannel = $channelManager->findChannelBySlug($slug);

        if ($activeChannel->isDm()) {
            return new Response($this->translator->trans('Opération non autorisée pour un message direct.'), 403);
        }

        if (!$this->isGranted('INVITE', $activeChannel)) {
            return new Response($this->translator->trans('Non autorisé.'), 403);
        }

        $userId = $request->request->getInt('userId');
        if ($userId <= 0) {
            return new Response($this->translator->trans('ID utilisateur manquant.'), 400);
        }

        $invitedUser = $userRepository->find($userId);
        if (!$invitedUser) {
            return new Response($this->translator->trans('Utilisateur non trouvé.'), 404);
        }

        try {
            $this->invitationManager->inviteToChannel($activeChannel, $currentUser, $invitedUser);
        } catch (InvalidArgumentException $e) {
            return new Response($e->getMessage(), 400);
        }

        $query = trim((string) $request->request->get('q', ''));
        $usersToInvite = $this->invitationManager->searchInvitableUsersForChannel($activeChannel, $currentUser, $query);

        return $this->render('dashboard/_invite_modal_results.html.twig', [
            'activeChannel' => $activeChannel,
            'usersToInvite' => $usersToInvite,
            'successMessage' => sprintf('%s a été invité !', $invitedUser->getUsername()),
            'searched' => $query !== '',
        ]);
    }

    #[Route('/channels/{slug}/invite/search', name: 'app_channel_invite_search', methods: ['GET'])]
    public function searchInvitableUsers(string $slug, Request $request, ChannelManager $channelManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $activeChannel = $channelManager->findChannelBySlug($slug);

        if ($activeChannel->isDm()) {
            return new Response($this->translator->trans('Opération non autorisée pour un message direct.'), 403);
        }

        if (!$this->isGranted('INVITE', $activeChannel)) {
            return new Response($this->translator->trans('Non autorisé.'), 403);
        }

        $query = trim((string) $request->query->get('q', ''));
        if ($query === '') {
            return $this->render('dashboard/_invite_modal_results.html.twig', [
                'usersToInvite' => [],
                'activeChannel' => $activeChannel,
                'searched' => false,
            ]);
        }

        $usersToInvite = $this->invitationManager->searchInvitableUsersForChannel($activeChannel, $currentUser, $query);

        return $this->render('dashboard/_invite_modal_results.html.twig', [
            'usersToInvite' => $usersToInvite,
            'activeChannel' => $activeChannel,
            'searched' => true,
        ]);
    }

    #[Route('/invitations/{id}/accept', name: 'app_invite_accept', methods: ['POST'])]
    public function acceptInvitation(int $id, Request $request, InvitationRepository $invitationRepository): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $invitation = $invitationRepository->find($id);
        if (!$invitation) {
            return new Response($this->translator->trans('Invitation non trouvée.'), 404);
        }

        try {
            $result = $this->invitationManager->acceptInvitation($invitation, $currentUser);
        } catch (AccessDeniedHttpException) {
            return new Response($this->translator->trans('Non autorisé.'), 403);
        } catch (InvalidArgumentException $e) {
            return new Response($e->getMessage(), 404);
        }

        $redirectUrl = match ($result['type']) {
            'channel' => $this->generateUrl('app_channel', ['slug' => $result['slug']]),
            'workspace_switch' => $this->generateUrl('app_workspace_switch', ['workspaceSlug' => $result['slug']]),
        };

        return $this->redirectOrHxRedirect($request, $redirectUrl);
    }

    #[Route('/invitations/{id}/reject', name: 'app_invite_reject', methods: ['POST'])]
    public function rejectInvitation(int $id, InvitationRepository $invitationRepository): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $invitation = $invitationRepository->find($id);
        if (!$invitation) {
            return new Response($this->translator->trans('Invitation non trouvée.'), 404);
        }

        try {
            $this->invitationManager->rejectInvitation($invitation, $currentUser);
        } catch (AccessDeniedHttpException) {
            return new Response($this->translator->trans('Non autorisé.'), 403);
        }

        return new Response('', 200);
    }
}
