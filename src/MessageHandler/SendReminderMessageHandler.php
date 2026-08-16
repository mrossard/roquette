<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\SendReminderMessage;
use App\Repository\ReminderRepository;
use App\Repository\UserRepository;
use App\Service\MessagePublishService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendReminderMessageHandler
{
    public function __construct(
        private ReminderRepository $reminderRepository,
        private UserRepository $userRepository,
        private MessagePublishService $messagePublishService,
    ) {}

    public function __invoke(SendReminderMessage $message): void
    {
        $reminder = $this->reminderRepository->find($message->reminderId);
        if (!$reminder || $reminder->getStatus() !== 'pending') {
            return;
        }

        $user = $reminder->getUser();
        $channel = $reminder->getChannel();

        // Récupérer le Robot Roquette comme auteur du message pour éviter qu'il soit ignoré
        $robotUser = $this->userRepository->findOneBy(['username' => User::ROBOT_USERNAME])
            ?? $this->userRepository->findOneBy([]);

        $reminderText = sprintf("⏰ **Rappel pour @%s** : %s", $user->getUsername(), $reminder->getMessage());

        // Publier le message via MessagePublishService avec l'auteur Robot.
        // MessagePublishService s'occupe de la diffusion sur Mercure (channel_notification)
        // et du déclenchement des notifications push pour les membres du canal.
        $this->messagePublishService->publish(
            channel: $channel,
            currentUser: $robotUser,
            messageText: $reminderText,
        );

        $reminder->setStatus('delivered');
        $this->reminderRepository->save($reminder, flush: true);
    }
}

