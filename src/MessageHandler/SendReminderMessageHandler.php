<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendReminderMessage;
use App\Repository\ReminderRepository;
use App\Repository\UserRepository;
use App\Service\MessagePublishService;
use App\Service\MercurePublisher;
use App\Service\PushNotificationService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendReminderMessageHandler
{
    public function __construct(
        private ReminderRepository $reminderRepository,
        private UserRepository $userRepository,
        private MessagePublishService $messagePublishService,
        private MercurePublisher $mercurePublisher,
        private PushNotificationService $pushNotificationService,
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
        $robotUser = $this->userRepository->findOneBy(['username' => 'robot-roquette'])
            ?? $this->userRepository->findOneBy([]);

        $reminderText = sprintf("⏰ **Rappel pour @%s** : %s", $user->getUsername(), $reminder->getMessage());

        // 1. Publier le message via MessagePublishService avec l'auteur Robot
        $result = $this->messagePublishService->publish(
            channel: $channel,
            currentUser: $robotUser,
            messageText: $reminderText,
        );

        // 2. Transmettre une notification personnelle explicite Mercure (Desktop/UI) au destinataire du rappel
        $notificationData = [
            'channelSlug' => $channel->getSlug(),
            'channelId' => $channel->getId(),
            'author' => $robotUser->getUsername(),
            'authorDisplayName' => 'Assistant Roquette',
            'channelName' => $channel->isDm() ? 'Assistant Roquette' : '#' . $channel->getName(),
            'content' => sprintf("⏰ Rappel : %s", $reminder->getMessage()),
            'isDm' => $channel->isDm(),
            'isMention' => true,
        ];
        $this->mercurePublisher->publishToUser($user, $notificationData, 'personal_notification');

        // 3. Envoyer une notification Push Web navigateur
        $this->pushNotificationService->sendToUser(
            $user,
            "⏰ Rappel Roquette",
            $reminder->getMessage(),
            sprintf("/channels/%s", $channel->getSlug())
        );

        $reminder->setStatus('delivered');
        $this->reminderRepository->save($reminder, flush: true);
    }
}
