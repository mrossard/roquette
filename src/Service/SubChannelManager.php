<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

class SubChannelManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ChannelRepository $channelRepository,
        private readonly ChannelAccessService $channelAccessService,
        private readonly AuditLoggerService $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly UniqueSlugGenerator $slugGenerator,
        private readonly KanbanManager $kanbanManager,
    ) {}

    /**
     * @param Channel[] $channels
     * @return array<int, list<Channel>>
     */
    public function buildSubChannelsByParent(array $channels): array
    {
        $map = [];
        foreach ($channels as $ch) {
            if (!$ch->isSubChannel() || !$ch->getParentMessage()) {
                continue;
            }

            $parentChannel = $ch->getParentMessage()->getChannel();
            if ($parentChannel === null) {
                continue;
            }

            $parentId = $parentChannel->getId();
            if ($parentId !== null) {
                $map[$parentId][] = $ch;
            }
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
        if ($parentChannel === null) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé.'));
        }

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
            'parent_channel_id' => $parentChannel?->getId(),
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
}
