<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Invitation;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\AuditAction;
use App\Repository\ChannelRepository;
use App\Repository\WorkspaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Service\UniqueSlugGenerator;
use Symfony\Contracts\Translation\TranslatorInterface;

class WorkspaceManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly ChannelRepository $channelRepository,
        private readonly MercurePublisher $mercurePublisher,
        private readonly AuditLoggerService $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly UniqueSlugGenerator $slugGenerator,
        private readonly \App\Service\Group\GroupProviderInterface $groupProvider,
    ) {}

    public function isUserMember(Workspace $workspace, User $user): bool
    {
        if ($workspace->isPublic()) {
            return true;
        }

        // Direct member
        if ($workspace->getMembers()->contains($user)) {
            return true;
        }

        // Check userGroup membership
        $userGroup = $workspace->getUserGroup();
        if ($userGroup !== null) {
            // Local group check
            if ($userGroup->getMembers()->contains($user)) {
                return true;
            }

            // LDAP/external group check
            $providerGroups = $this->groupProvider->getGroupsForUser($user);
            $providerIdentifiers = array_map(static fn($g) => (string) $g->identifier, $providerGroups);
            if (in_array($userGroup->getGroupIdentifier(), $providerIdentifiers, true)) {
                return true;
            }
        }

        return false;
    }

    public function create(string $name, ?string $description, User $creator): Workspace
    {
        $slug = $this->slugGenerator->generate(
            $name,
            'workspace',
            fn(string $s) => $this->workspaceRepository->findOneBy(['slug' => $s]) !== null,
        );

        $workspace = new Workspace();
        $workspace->setName($name);
        $workspace->setSlug($slug);
        $workspace->setDescription($description !== '' ? $description : null);
        $workspace->setCreator($creator);
        $workspace->addMember($creator);

        $this->entityManager->persist($workspace);

        // Create a default "general" channel in the workspace
        $generalName = $this->translator->trans('channel.general.name', [], 'messages');
        $generalDesc = $this->translator->trans('channel.general.description', [], 'messages');

        $generalSlug = 'general-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $baseGeneralSlug = $generalSlug;
        $gCount = 1;
        while ($this->channelRepository->findOneBy(['slug' => $generalSlug])) {
            $generalSlug = $baseGeneralSlug . '-' . rand(100, 999);
            if ($gCount++ > 20) {
                $generalSlug = $baseGeneralSlug . '-' . uniqid();
                break;
            }
        }

        $generalChannel = new Channel();
        $generalChannel->setName($generalName);
        $generalChannel->setSlug($generalSlug);
        $generalChannel->setDescription($generalDesc);
        $generalChannel->setCreator($creator);
        $workspace->addChannel($generalChannel);
        $generalChannel->addMember($creator);

        $this->entityManager->persist($generalChannel);
        $this->entityManager->flush();

        $this->auditLogger->log(AuditAction::CHANNEL_CREATE, $creator, [
            'workspace_id' => $workspace->getId(),
            'workspace_name' => $workspace->getName(),
            'slug' => $workspace->getSlug(),
        ]);

        $this->logger->info(sprintf(
            'Workspace created: "%s" (slug: "%s") by user "%s"',
            $workspace->getName(),
            $workspace->getSlug(),
            $creator->getUsername(),
        ));

        return $workspace;
    }

    public function addMember(Workspace $workspace, User $user): void
    {
        $workspace->addMember($user);
        $this->entityManager->flush();
    }

    public function removeMember(Workspace $workspace, User $user): void
    {
        $workspace->removeMember($user);
        $this->entityManager->flush();
    }

    public function update(Workspace $workspace, string $name, ?string $description): void
    {
        $workspace->setName($name);
        $workspace->setDescription($description !== '' ? $description : null);

        $newSlug = $this->slugGenerator->generate(
            $name,
            'workspace',
            fn(string $s) => ($existing = $this->workspaceRepository->findOneBy(['slug' => $s])) !== null && $existing->getId() !== $workspace->getId(),
        );
        $workspace->setSlug($newSlug);

        $this->entityManager->flush();
    }

    public function delete(Workspace $workspace, User $currentUser): void
    {
        if ($workspace->isPublic()) {
            throw new \RuntimeException($this->translator->trans('Impossible de supprimer le workspace public.'));
        }

        $channels = $workspace->getChannels();
        foreach ($channels as $channel) {
            $this->mercurePublisher->publishToChannel(
                $channel,
                [
                    'channelSlug' => $channel->getSlug(),
                    'redirectUrl' => '/',
                ],
                'channel_deleted',
            );
        }

        $this->auditLogger->log(AuditAction::CHANNEL_DELETE, $currentUser, [
            'workspace_id' => $workspace->getId(),
            'workspace_name' => $workspace->getName(),
        ]);

        $this->entityManager->remove($workspace);
        $this->entityManager->flush();
    }

    /** @return Channel[] */
    public function getChannelsForUser(Workspace $workspace, User $user): array
    {
        return $this->channelRepository->findForWorkspace($workspace, $user);
    }

    public function getDefaultChannel(Workspace $workspace): ?Channel
    {
        $channels = $this->channelRepository->findBy(
            ['workspace' => $workspace, 'parentMessage' => null, 'isDm' => false],
            ['createdAt' => 'ASC'],
            1,
        );

        return $channels[0] ?? null;
    }

    public function findWorkspaceBySlug(string $slug): Workspace
    {
        $workspace = $this->workspaceRepository->findOneBy(['slug' => $slug]);
        if (!$workspace) {
            throw new NotFoundHttpException($this->translator->trans('Espace non trouvé.'));
        }

        return $workspace;
    }

    public function inviteUser(Workspace $workspace, User $invitedBy, User $invitee): Invitation
    {
        $invitation = new Invitation();
        $invitation->setInvitee($invitee);
        $invitation->setWorkspace($workspace);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        return $invitation;
    }

    public function acceptInvitation(Invitation $invitation, User $user): void
    {
        $workspace = $invitation->getWorkspace();
        if (!$workspace) {
            throw new \RuntimeException('Invitation invalide.');
        }

        $workspace->addMember($user);
        $this->entityManager->remove($invitation);
        $this->entityManager->flush();
    }

    public function rejectInvitation(Invitation $invitation): void
    {
        $this->entityManager->remove($invitation);
        $this->entityManager->flush();
    }
}
