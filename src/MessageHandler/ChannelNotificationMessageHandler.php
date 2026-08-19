<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ChannelNotificationMessage;
use App\Repository\ChannelRepository;
use App\Service\PushNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ChannelNotificationMessageHandler
{
    public function __construct(
        private readonly ChannelRepository $channelRepository,
        private readonly PushNotificationService $pushNotificationService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ChannelNotificationMessage $message): void
    {
        $channel = $this->channelRepository->find($message->getChannelId());
        if ($channel === null) {
            return;
        }

        $tag = 'channel-' . $channel->getSlug();

        foreach ($channel->getMembers() as $member) {
            if ($member->getId() === $message->getAuthorId()) {
                continue;
            }

            try {
                $this->pushNotificationService->sendToUser(
                    $member,
                    $message->getTitle(),
                    $message->getBody(),
                    $message->getUrl(),
                    $tag,
                );
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send push notification in channel batch', [
                    'userId' => $member->getId(),
                    'channelId' => $message->getChannelId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
