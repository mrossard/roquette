<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PushNotificationMessage;
use App\Repository\UserRepository;
use App\Service\PushNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class PushNotificationMessageHandler
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PushNotificationService $pushNotificationService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(PushNotificationMessage $message): void
    {
        $user = $this->userRepository->find($message->getUserId());
        if ($user === null) {
            return;
        }

        try {
            $this->pushNotificationService->sendToUser(
                $user,
                $message->getTitle(),
                $message->getBody(),
                $message->getUrl(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send push notification', [
                'userId' => $message->getUserId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
