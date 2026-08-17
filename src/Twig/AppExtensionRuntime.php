<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Repository\UserChannelReadRepository;
use App\Service\LinkPreviewService;
use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\RuntimeExtensionInterface;

class AppExtensionRuntime implements RuntimeExtensionInterface
{
    private ?array $subchannelCache = null;

    public function __construct(
        private readonly LinkPreviewService $linkPreviewService,
        private readonly ChannelRepository $channelRepository,
        private readonly UserChannelReadRepository $ucrRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisher $mercurePublisher,
        private readonly ?MessageRepository $messageRepository = null,
    ) {}

    public function getCachedLinkPreview(string $url): ?array
    {
        return $this->linkPreviewService->getCachedPreview($url);
    }

    public function resetSubchannelCache(): void
    {
        $this->subchannelCache = null;
    }

    public function getSubchannel(Message $message): ?Channel
    {
        $messageId = $message->getId();
        if ($messageId === null) {
            return null;
        }

        if ($this->subchannelCache === null) {
            $this->subchannelCache = [];
            $em = $this->entityManager;
            $messages = $em->getUnitOfWork()->getIdentityMap()[Message::class] ?? [];
            $messageIds = array_keys($messages);

            if ($messageIds !== []) {
                $channels = $this->channelRepository
                    ->createQueryBuilder('c')
                    ->where('c.parentMessage IN (:messageIds)')
                    ->setParameter('messageIds', $messageIds)
                    ->getQuery()
                    ->getResult();

                foreach ($messageIds as $id) {
                    $this->subchannelCache[$id] = null;
                }

                foreach ($channels as $channel) {
                    if ($channel->getParentMessage() === null) {
                        continue;
                    }

                    $this->subchannelCache[$channel->getParentMessage()->getId()] = $channel;
                }
            }
        }

        return $this->subchannelCache[$messageId] ?? null;
    }

    public function getUserMercureTopics(User $user): array
    {
        $topics = [
            $this->mercurePublisher->getUserTopic($user),
            $this->mercurePublisher->getStatusTopic(),
        ];

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $topics[] = $this->mercurePublisher->getAdminModerationTopic();
        }

        $channels = $this->channelRepository->findAllForUser($user);
        foreach ($channels as $ch) {
            $topics[] = $this->mercurePublisher->getChannelTopic($ch);
        }

        return $topics;
    }

    public function getUserChannelNotificationsMap(User $user): array
    {
        $channels = $this->channelRepository->findAllForUser($user);
        $unreadCounts = $this->ucrRepository->getUnreadCounts($user);

        $map = [];
        foreach ($channels as $channel) {
            $slug = $channel->getSlug();
            $unread = $unreadCounts[$channel->getId()] ?? null;
            $enabled = $unread ? $unread['notificationsEnabled'] : $channel->isDm();
            $map[$slug] = $enabled;
        }

        return $map;
    }

    public function getPendingModerationCount(): int
    {
        return $this->messageRepository?->countPendingModeration() ?? 0;
    }
}
