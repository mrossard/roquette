<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;

class ActivitySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private string $mercureTopicPrefix,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $now = new \DateTimeImmutable();
        $lastActive = $user->getLastActiveAt();

        // Check status prior to update
        $oldStatus = $user->getStatus();

        // Only update if last active was more than 1 minute ago (60 seconds)
        if ($lastActive === null || ($now->getTimestamp() - $lastActive->getTimestamp()) > 60) {
            $user->setLastActiveAt($now);
            $this->entityManager->flush();

            $newStatus = $user->getStatus();
            $update = new Update($this->mercureTopicPrefix . '/users/status', json_encode([
                'type' => 'user_status_changed',
                'username' => $user->getUsername(),
                'status' => $newStatus,
                'statusLabel' => $user->getStatusLabel(),
                'statusOverride' => $user->getStatusOverride() ?? 'auto',
                'lastActive' => $now->getTimestamp(),
            ]));
            $this->bus->dispatch($update);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }
}
