<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Channel\CreateChannelDto;
use App\Dto\Channel\UpdateChannelDto;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\AuditAction;
use App\Repository\ChannelRepository;
use App\Service\Group\GroupSubscriptionManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ChannelManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ChannelRepository $channelRepository,
        private readonly ChannelAccessService $channelAccessService,
        private readonly MercurePublisher $mercurePublisher,
        private readonly AuditLoggerService $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly UniqueSlugGenerator $slugGenerator,
        private readonly KanbanManager $kanbanManager,
        private readonly WorkspaceManager $workspaceManager,
        private readonly GroupSubscriptionManager $groupSubscriptionManager,
        private readonly ?RobotUserProvider $robotUserProvider = null,
    ) {}

    public function create(CreateChannelDto $dto, User $user): Channel
    {
        $slug = $this->slugGenerator->generate(
            $dto->name,
            'channel',
            fn(string $s) => $this->channelRepository->findOneBy(['slug' => $s]) !== null,
        );

        $channel = new Channel();
        $channel->setName($dto->name);
        $channel->setSlug($slug);
        $channel->setDescription($dto->description);
        $channel->setCreator($user);
        $channel->addMember($user);

        // Workspace assignment
        if ($dto->workspace instanceof Workspace) {
            if (!$this->workspaceManager->isUserMember($dto->workspace, $user)) {
                throw new \InvalidArgumentException($this->translator->trans(
                    'Vous ne pouvez pas créer un canal dans cet espace de travail.',
                ));
            }

            $channel->setWorkspace($dto->workspace);
            $channel->setIsPrivate(false);
        }

        if ($dto->isPrivate) {
            $channel->setIsPrivate(true);

            if ($dto->groupIdentifier !== '') {
                $groupSub = $this->groupSubscriptionManager->attachGroupSubscription(
                    $channel,
                    $dto->groupIdentifier,
                    $dto->isGroupChannel,
                );
                $this->entityManager->persist($groupSub);
            }
        }

        if ($dto->isTodoList) {
            $channel->setIsTodoList(true);
        }

        $channel->setMessageRetentionMonths($dto->retentionMonths);

        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        if ($channel->isTodoList()) {
            $this->kanbanManager->initializeDefaultColumns($channel);
        }

        $this->auditLogger->log(AuditAction::CHANNEL_CREATE, $user, [
            'channel_id' => $channel->getId(),
            'channel_name' => $channel->getName(),
            'slug' => $channel->getSlug(),
            'is_private' => $channel->isPrivate(),
        ]);

        $this->logger->info(sprintf(
            'Channel created: "%s" (slug: "%s", private: %s) by user "%s"',
            $channel->getName(),
            $channel->getSlug(),
            $channel->isPrivate() ? 'yes' : 'no',
            $user->getUsername(),
        ));

        return $channel;
    }

    public function update(Channel $channel, UpdateChannelDto $dto, User $user): void
    {
        $isAdmin = $this->isCurrentUserAdmin() || $channel->isAdministrator($user);
        if (!$isAdmin) {
            throw new AccessDeniedHttpException($this->translator->trans(
                'Vous n\'êtes pas autorisé à modifier les paramètres de ce canal.',
            ));
        }

        if ($channel->getName() !== $dto->name) {
            $newSlug = $this->slugGenerator->generate(
                $dto->name,
                'channel',
                fn(string $s) => ($existing = $this->channelRepository->findOneBy(['slug' => $s])) !== null && $existing->getId() !== $channel->getId(),
            );
            $channel->setSlug($newSlug);
            $channel->setName($dto->name);
        }

        $channel->setDescription($dto->description);

        if ($channel->isSubChannel()) {
            $channel->setIsTodoList($dto->isTodoList);
        }

        $channel->setMessageRetentionMonths($dto->retentionMonths);

        foreach ($channel->getAdministrators() as $admin) {
            if (in_array((string) $admin->getId(), array_map('strval', $dto->administratorIds), true)) {
                continue;
            }

            $channel->removeAdministrator($admin);
        }

        $userRepository = $this->entityManager->getRepository(User::class);
        foreach ($dto->administratorIds as $adminId) {
            $adminUser = $userRepository->find((int) $adminId);
            if ($adminUser && $adminUser !== $channel->getCreator()) {
                if ($channel->getMembers()->contains($adminUser)) {
                    $channel->addAdministrator($adminUser);
                }
            }
        }

        $this->entityManager->flush();

        $this->logger->info(sprintf(
            'Channel updated: "%s" (slug: "%s") by user "%s"',
            $channel->getName(),
            $channel->getSlug(),
            $user->getUsername(),
        ));
    }

    public function delete(Channel $channel, User $currentUser): string
    {
        $isAdmin =
            $this->isCurrentUserAdmin()
            || $channel->getCreator() && $channel->getCreator()->getId() === $currentUser->getId();
        if (!$isAdmin) {
            throw new AccessDeniedHttpException($this->translator->trans(
                'Vous n\'êtes pas autorisé à supprimer ce canal.',
            ));
        }

        $parentChannel = $channel->getParentMessage()?->getChannel();
        $redirectSlug = $parentChannel ? $parentChannel->getSlug() : null;

        $this->mercurePublisher->publishToChannel(
            $channel,
            [
                'channelSlug' => $channel->getSlug(),
                'redirectUrl' => $redirectSlug ? '/channels/' . $redirectSlug : '/',
            ],
            'channel_deleted',
        );

        $this->auditLogger->log(AuditAction::CHANNEL_DELETE, $currentUser, [
            'channel_id' => $channel->getId(),
            'channel_name' => $channel->getName(),
            'slug' => $channel->getSlug(),
            'is_subchannel' => $channel->isSubChannel(),
        ]);

        $this->logger->info(sprintf(
            'Channel deleted: "%s" (slug: "%s") by user "%s"',
            $channel->getName(),
            $channel->getSlug(),
            $currentUser->getUsername(),
        ));

        $this->entityManager->remove($channel);
        $this->entityManager->flush();

        return $redirectSlug ?? 'dashboard';
    }

    public function updateRetention(Channel $channel, ?int $retentionMonths, User $currentUser): void
    {
        $isAdmin = $this->isCurrentUserAdmin() || $channel->isAdministrator($currentUser);
        if (!$isAdmin) {
            throw new AccessDeniedHttpException($this->translator->trans(
                'Vous n\'êtes pas autorisé à modifier la rétention de ce canal.',
            ));
        }

        $channel->setMessageRetentionMonths($retentionMonths === 0 ? null : $retentionMonths);
        $this->entityManager->flush();
    }

    public function findChannelBySlug(string $slug): Channel
    {
        $channel = $this->channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            throw new NotFoundHttpException($this->translator->trans('Canal non trouvé.'));
        }

        return $channel;
    }

    public function buildSubChannelsByParent(array $subChannels): array
    {
        $map = [];
        foreach ($subChannels as $ch) {
            if (!$ch->isSubChannel() || !$ch->getParentMessage()) {
                continue;
            }

            $parentId = $ch->getParentMessage()->getChannel()->getId();
            $map[$parentId][] = $ch;
        }

        return $map;
    }

    public function createSubChannel(Message $parentMessage, User $currentUser): Channel
    {
        $existingSubChannel = $this->channelRepository->findOneBy(['parentMessage' => $parentMessage]);
        if ($existingSubChannel) {
            return $existingSubChannel;
        }

        $channel = $this->buildSubChannel($parentMessage, $currentUser);
        $this->saveSubChannel($channel, $parentMessage, $currentUser);

        return $channel;
    }

    public function createTodoListSubChannel(Message $parentMessage, User $currentUser): Channel
    {
        $existingSubChannel = $this->channelRepository->findOneBy(['parentMessage' => $parentMessage]);
        if ($existingSubChannel) {
            return $existingSubChannel;
        }

        $channel = $this->buildSubChannel($parentMessage, $currentUser);
        $channel->setIsTodoList(true);
        $this->saveSubChannel($channel, $parentMessage, $currentUser);
        $this->kanbanManager->initializeDefaultColumns($channel);

        return $channel;
    }

    private function buildSubChannel(Message $parentMessage, User $currentUser): Channel
    {
        $parentChannel = $parentMessage->getChannel();
        if ($parentChannel->isSubChannel() && !$parentChannel->isTodoList()) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé.'));
        }

        if (!$this->channelAccessService->canUserAccess($parentChannel, $currentUser)) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé.'));
        }

        $content = $parentMessage->getContent() ?? $parentMessage->getFileName() ?? 'Discussion';
        $name = mb_substr(trim((string) preg_replace('/\s+/', ' ', $content)), 0, 40);

        $slug = $this->slugGenerator->generate(
            'sc-' . $name,
            'sc-discussion',
            fn(string $s) => $this->channelRepository->findOneBy(['slug' => $s]) !== null,
        );

        $channel = new Channel();
        $channel->setName($name);
        $channel->setSlug($slug);
        $channel->setDescription($this->translator->trans('Discussion créée depuis un message.'));
        $channel->setParentMessage($parentMessage);
        $channel->setCreator($currentUser);
        $channel->setIsPrivate($parentChannel->isPrivate());
        $channel->setMessageRetentionMonths($parentChannel->getMessageRetentionMonths());
        if ($parentChannel->getWorkspace()) {
            $channel->setWorkspace($parentChannel->getWorkspace());
        }

        foreach ($parentChannel->getMembers() as $member) {
            $channel->addMember($member);
        }

        return $channel;
    }

    private function saveSubChannel(Channel $channel, Message $parentMessage, User $currentUser): void
    {
        $parentChannel = $parentMessage->getChannel();

        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        $this->auditLogger->log(AuditAction::CHANNEL_CREATE, $currentUser, [
            'channel_id' => $channel->getId(),
            'channel_name' => $channel->getName(),
            'slug' => $channel->getSlug(),
            'is_private' => $channel->isPrivate(),
            'parent_channel_id' => $parentChannel->getId(),
            'parent_message_id' => $parentMessage->getId(),
        ]);

        $this->logger->info(sprintf(
            'Sub-channel created: "%s" (slug: "%s", todo: %s) from message #%d by user "%s"',
            $channel->getName(),
            $channel->getSlug(),
            $channel->isTodoList() ? 'yes' : 'no',
            $parentMessage->getId(),
            $currentUser->getUsername(),
        ));
    }

    public function getOrCreateDm(User $currentUser, User $partner): Channel
    {
        if ($partner->getId() === $currentUser->getId()) {
            throw new \InvalidArgumentException($this->translator->trans(
                'Vous ne pouvez pas envoyer de message direct à vous-même.',
            ));
        }

        $dmChannel = $this->channelRepository->findDmBetween($currentUser, $partner);

        if (!$dmChannel) {
            $dmChannel = new Channel();
            $dmChannel->setIsPrivate(true);
            $dmChannel->setIsDm(true);

            $slug = $this->generateDmSlug($currentUser, $partner);
            $dmChannel->setSlug($slug);
            $dmChannel->setName(sprintf('%s & %s', $currentUser->getUsername(), $partner->getUsername()));
            $dmChannel->setDescription(sprintf(
                'Conversation privée entre %s et %s',
                $currentUser->getUsername(),
                $partner->getUsername(),
            ));

            $dmChannel->setCreator($currentUser);
            $dmChannel->addMember($currentUser);
            $dmChannel->addMember($partner);

            $this->entityManager->persist($dmChannel);
            $this->entityManager->flush();
        } elseif (!$dmChannel->getMembers()->contains($currentUser)) {
            $dmChannel->addMember($currentUser);
            $this->entityManager->flush();
        }

        return $dmChannel;
    }

    public function generateDmSlug(User $user1, User $user2): string
    {
        if ($this->robotUserProvider !== null) {
            if ($this->robotUserProvider->isRobotUser($user1)) {
                return $this->robotUserProvider->getDmChannelSlug($user2);
            }
            if ($this->robotUserProvider->isRobotUser($user2)) {
                return $this->robotUserProvider->getDmChannelSlug($user1);
            }
        }

        $minId = min((int) $user1->getId(), (int) $user2->getId());
        $maxId = max((int) $user1->getId(), (int) $user2->getId());

        return sprintf('dm-%d-%d', $minId, $maxId);
    }

    private function isCurrentUserAdmin(): bool
    {
        return $this->authorizationChecker->isGranted('ROLE_ADMIN');
    }
}
