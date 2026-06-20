<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;

class PushNotificationService
{
    public function __construct(
        private readonly WebPush $webPush,
        private readonly PushSubscriptionRepository $subscriptionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $vapidPublicKey,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendToUser(User $user, string $title, string $body, string $url): void
    {
        $subscriptions = $this->subscriptionRepository->findByUser($user);

        if ($subscriptions === []) {
            return;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'tag' => 'roquette-message',
        ]);

        foreach ($subscriptions as $sub) {
            $this->queueAndSend($sub, $payload);
        }
    }

    public function sendToUsers(iterable $users, string $title, string $body, string $url): void
    {
        foreach ($users as $user) {
            $this->sendToUser($user, $title, $body, $url);
        }
    }

    public function getPublicKey(): string
    {
        return $this->vapidPublicKey;
    }

    private function queueAndSend(PushSubscription $sub, string $payload): void
    {
        try {
            $subscription = Subscription::create([
                'endpoint' => $sub->getEndpoint(),
                'keys' => [
                    'p256dh' => $sub->getPublicKey(),
                    'auth' => $sub->getAuthToken(),
                ],
            ]);

            $this->webPush->queueNotification($subscription, $payload);

            foreach ($this->webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    continue;
                }

                $endpoint = $report->getEndpoint();
                $this->logger->warning('Web Push failed', [
                    'endpoint' => $endpoint,
                    'reason' => $report->getReason(),
                ]);

                if ($report->isSubscriptionExpired()) {
                    $this->removeExpiredSubscription($endpoint);
                }
            }
        } catch (\ErrorException $e) {
            $this->logger->error('Web Push error', [
                'endpoint' => $sub->getEndpoint(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function removeExpiredSubscription(string $endpoint): void
    {
        $sub = $this->subscriptionRepository->findOneBy(['endpoint' => $endpoint]);
        if ($sub !== null) {
            $this->entityManager->remove($sub);
            $this->entityManager->flush();
        }
    }
}
