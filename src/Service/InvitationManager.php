<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Invitation;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Centralizes channel and workspace invitations, Mercure dispatches, acceptance and rejection workflows.
 */
class InvitationManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly MercurePublisher $mercurePublisher,
        private readonly WorkspaceManager $workspaceManager,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {}

    public function inviteToChannel(Channel $channel, User $inviter, User $invitee): Invitation
    {
        if ($channel->isDm()) {
            throw new InvalidArgumentException($this->translator->trans(
                'Opération non autorisée pour un message direct.',
            ));
        }

        $invitation = new Invitation();
        $invitation->setChannel($channel);
        $invitation->setInvitee($invitee);

        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $this->logger->info(sprintf(
            'User "%s" invited user "%s" to channel "%s" (slug: "%s")',
            $inviter->getUsername(),
            $invitee->getUsername(),
            $channel->getName(),
            $channel->getSlug(),
        ));

        $sidebarHtml = $this->twig->render('dashboard/_invite_sidebar_item.html.twig', [
            'invite' => $invitation,
        ]);

        $senderName =
            $inviter->getDisplayName() !== null && $inviter->getDisplayName() !== ''
                ? $inviter->getDisplayName()
                : $inviter->getUsername();

        $this->mercurePublisher->publishToUser(
            $invitee,
            [
                'type' => 'invitation_received',
                'invitedUsername' => $invitee->getUsername(),
                'invitationId' => $invitation->getId(),
                'channelSlug' => $channel->getSlug(),
                'channelName' => $channel->getName(),
                'senderName' => $senderName,
                'html' => $sidebarHtml,
            ],
            'invitation_received',
        );

        return $invitation;
    }

    public function inviteToWorkspace(Workspace $workspace, User $inviter, User $invitee): Invitation
    {
        $invitation = new Invitation();
        $invitation->setInvitee($invitee);
        $invitation->setWorkspace($workspace);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        return $invitation;
    }

    /**
     * Accepts an invitation and returns the slug or route redirection data.
     *
     * @return array{type: 'channel'|'workspace'|'workspace_switch', slug: string}
     */
    public function acceptInvitation(Invitation $invitation, User $currentUser): array
    {
        if ($invitation->getInvitee() !== $currentUser) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé.'));
        }

        $workspace = $invitation->getWorkspace();
        if ($workspace !== null) {
            $workspace->addMember($currentUser);
            $this->entityManager->remove($invitation);
            $this->entityManager->flush();

            $this->logger->info(sprintf(
                'User "%s" accepted invitation to workspace "%s" (slug: "%s")',
                $currentUser->getUsername(),
                $workspace->getName(),
                $workspace->getSlug(),
            ));

            $defaultChannel = $this->workspaceManager->getDefaultChannel($workspace);
            if ($defaultChannel !== null) {
                return ['type' => 'channel', 'slug' => $defaultChannel->getSlug()];
            }

            return ['type' => 'workspace_switch', 'slug' => $workspace->getSlug()];
        }

        $channel = $invitation->getChannel();
        if ($channel === null) {
            throw new InvalidArgumentException($this->translator->trans('Invitation invalide.'));
        }

        $channel->addMember($currentUser);
        $this->entityManager->remove($invitation);
        $this->entityManager->flush();

        $this->logger->info(sprintf(
            'User "%s" accepted invitation to channel "%s" (slug: "%s")',
            $currentUser->getUsername(),
            $channel->getName(),
            $channel->getSlug(),
        ));

        return ['type' => 'channel', 'slug' => $channel->getSlug()];
    }

    public function rejectInvitation(Invitation $invitation, User $currentUser): void
    {
        if ($invitation->getInvitee() !== $currentUser) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé.'));
        }

        $workspace = $invitation->getWorkspace();
        if ($workspace !== null) {
            $this->entityManager->remove($invitation);
            $this->entityManager->flush();

            $this->logger->info(sprintf(
                'User "%s" rejected invitation to workspace "%s" (slug: "%s")',
                $currentUser->getUsername(),
                $workspace->getName(),
                $workspace->getSlug(),
            ));
            return;
        }

        $channel = $invitation->getChannel();
        $this->logger->info(sprintf(
            'User "%s" rejected invitation (ID: %d) to channel "%s" (slug: "%s")',
            $currentUser->getUsername(),
            $invitation->getId(),
            $channel?->getName() ?? '?',
            $channel?->getSlug() ?? '?',
        ));

        $this->entityManager->remove($invitation);
        $this->entityManager->flush();
    }

    /**
     * @return list<User>
     */
    public function searchInvitableUsersForChannel(Channel $channel, User $currentUser, string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        return $this->userRepository->findInvitableForChannel($channel, $currentUser, $query);
    }

    /**
     * @return list<User>
     */
    public function searchInvitableUsersForWorkspace(Workspace $workspace, User $currentUser, string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        return $this->workspaceRepository->findMembersNotInWorkspace($workspace, $currentUser, $query);
    }
}
