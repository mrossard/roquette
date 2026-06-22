<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\GroupSubscription;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ChannelManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ChannelRepository $channelRepository,
        private readonly MercurePublisher $mercurePublisher,
        private readonly AuditLoggerService $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly SluggerInterface $slugger,
    ) {}

    public function create(string $name, string $description, array $extra, User $currentUser): Channel
    {
        $slug = strtolower($this->slugger->slug($name)->toString());
        if ($slug === '') {
            $slug = 'channel-' . uniqid();
        }

        $baseSlug = $slug;
        $count = 1;
        while ($this->channelRepository->findOneBy(['slug' => $slug])) {
            $slug = $baseSlug . '-' . rand(100, 999);
            if ($count++ > 20) {
                $slug = $baseSlug . '-' . uniqid();
                break;
            }
        }

        $channel = new Channel();
        $channel->setName($name);
        $channel->setSlug($slug);
        $channel->setDescription($description);
        $channel->setCreator($currentUser);
        $channel->addMember($currentUser);

        $isPrivate = $extra['isPrivate'] ?? false;
        if ($isPrivate) {
            $channel->setIsPrivate(true);

            $groupIdentifier = $extra['groupIdentifier'] ?? '';
            if ($groupIdentifier !== '') {
                $isGroupChannel = $extra['isGroupChannel'] ?? false;

                if ($isGroupChannel) {
                    $existingGroupSub = $this->entityManager
                        ->getRepository(GroupSubscription::class)
                        ->findOneBy([
                            'groupIdentifier' => $groupIdentifier,
                            'isGroupChannel' => true,
                        ]);
                    if ($existingGroupSub) {
                        throw new \InvalidArgumentException($this->translator->trans(
                            'Ce groupe possède déjà un canal officiel.',
                        ));
                    }
                }

                $groupSubscription = new GroupSubscription();
                $groupSubscription->setGroupIdentifier($groupIdentifier);
                $groupSubscription->setIsGroupChannel($isGroupChannel);
                $channel->addGroupSubscription($groupSubscription);
                $this->entityManager->persist($groupSubscription);
            }
        }

        if ($extra['isTodoList'] ?? false) {
            $channel->setIsTodoList(true);
        }

        $retention = $extra['retentionMonths'] ?? null;
        if ($retention !== null && $retention !== '') {
            $retentionVal = (int) $retention;
            $channel->setMessageRetentionMonths($retentionVal === 0 ? null : $retentionVal);
        } else {
            $channel->setMessageRetentionMonths(6);
        }

        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        $this->auditLogger->log(AuditAction::CHANNEL_CREATE, $currentUser, [
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
            $currentUser->getUsername(),
        ));

        return $channel;
    }

    public function update(Channel $channel, string $name, string $description, array $extra, User $currentUser): void
    {
        $isAdmin = $this->isCurrentUserAdmin() || $channel->isAdministrator($currentUser);
        if (!$isAdmin) {
            throw new AccessDeniedHttpException($this->translator->trans(
                'Vous n\'êtes pas autorisé à modifier les paramètres de ce canal.',
            ));
        }

        if ($channel->getName() !== $name) {
            $newSlug = strtolower($this->slugger->slug($name)->toString());
            if ($newSlug === '') {
                $newSlug = 'channel-' . uniqid();
            }

            if ($newSlug !== $channel->getSlug()) {
                $baseSlug = $newSlug;
                $count = 1;
                while (true) {
                    $existing = $this->channelRepository->findOneBy(['slug' => $newSlug]);
                    if (!$existing || $existing->getId() === $channel->getId()) {
                        break;
                    }
                    $newSlug = $baseSlug . '-' . rand(100, 999);
                    if ($count++ > 20) {
                        $newSlug = $baseSlug . '-' . uniqid();
                        break;
                    }
                }
                $channel->setSlug($newSlug);
            }
            $channel->setName($name);
        }

        $channel->setDescription($description);

        if ($channel->isSubChannel()) {
            $channel->setIsTodoList($extra['isTodoList'] ?? false);
        }

        $retention = $extra['retentionMonths'] ?? null;
        if ($retention !== null && $retention !== '') {
            $retentionVal = (int) $retention;
            $channel->setMessageRetentionMonths($retentionVal === 0 ? null : $retentionVal);
        } else {
            $channel->setMessageRetentionMonths(6);
        }

        $adminIds = $extra['administratorIds'] ?? [];
        foreach ($channel->getAdministrators() as $admin) {
            if (in_array((string) $admin->getId(), $adminIds, true)) {
                continue;
            }

            $channel->removeAdministrator($admin);
        }

        $userRepository = $this->entityManager->getRepository(User::class);
        foreach ($adminIds as $adminId) {
            $adminUser = $userRepository->find((int) $adminId);
            if ($adminUser && $adminUser !== $channel->getCreator()) {
                if ($channel->getMembers()->contains($adminUser)) {
                    $channel->addAdministrator($adminUser);
                }
            }
        }

        $this->entityManager->flush();
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
        return $this->doCreateSubChannel($parentMessage, $currentUser, false);
    }

    public function createTodoListSubChannel(Message $parentMessage, User $currentUser): Channel
    {
        return $this->doCreateSubChannel($parentMessage, $currentUser, true);
    }

    private function doCreateSubChannel(Message $parentMessage, User $currentUser, bool $isTodoList): Channel
    {
        $existingSubChannel = $this->channelRepository->findOneBy(['parentMessage' => $parentMessage]);
        if ($existingSubChannel) {
            return $existingSubChannel;
        }

        $parentChannel = $parentMessage->getChannel();
        if ($parentChannel->isSubChannel() && !$parentChannel->isTodoList()) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé.'));
        }

        if (!$this->channelRepository->canUserAccess($parentChannel, $currentUser)) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé.'));
        }

        $content = $parentMessage->getContent() ?? $parentMessage->getFileName() ?? 'Discussion';
        $name = mb_substr(trim(preg_replace('/\s+/', ' ', $content)), 0, 40);

        $sluggedName = strtolower($this->slugger->slug($name)->toString());
        if ($sluggedName === '') {
            $sluggedName = 'discussion';
        }
        $slug = 'sc-' . $sluggedName . '-' . substr(bin2hex(random_bytes(3)), 0, 6);

        $baseSlug = $slug;
        $count = 1;
        while ($this->channelRepository->findOneBy(['slug' => $slug])) {
            $slug = $baseSlug . '-' . rand(100, 999);
            if ($count++ > 20) {
                $slug = $baseSlug . '-' . uniqid();
                break;
            }
        }

        $channel = new Channel();
        $channel->setName($name);
        $channel->setSlug($slug);
        $channel->setDescription($this->translator->trans('Discussion créée depuis un message.'));
        $channel->setParentMessage($parentMessage);
        $channel->setCreator($currentUser);
        $channel->setIsPrivate($parentChannel->isPrivate());
        $channel->setMessageRetentionMonths($parentChannel->getMessageRetentionMonths());
        if ($isTodoList) {
            $channel->setIsTodoList(true);
        }

        foreach ($parentChannel->getMembers() as $member) {
            $channel->addMember($member);
        }

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
            $isTodoList ? 'yes' : 'no',
            $parentMessage->getId(),
            $currentUser->getUsername(),
        ));

        return $channel;
    }

    private function isCurrentUserAdmin(): bool
    {
        return $this->authorizationChecker->isGranted('ROLE_ADMIN');
    }
}
