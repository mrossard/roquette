<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Channel;
use App\Entity\Reminder;
use App\Entity\User;
use App\Message\SendReminderMessage;
use App\Repository\ReminderRepository;
use App\Repository\UserRepository;
use App\Service\DmManager;
use App\Service\MessagePublishService;
use App\Service\RobotUserProvider;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendReminderMessageHandler
{
    public function __construct(
        private ReminderRepository $reminderRepository,
        private UserRepository $userRepository,
        private MessagePublishService $messagePublishService,
        private RobotUserProvider $robotUserProvider,
        private ?DmManager $dmManager = null,
    ) {}

    public function __invoke(SendReminderMessage $message): void
    {
        $reminder = $this->reminderRepository->find($message->reminderId);
        if (!$reminder || $reminder->getStatus() !== 'pending') {
            return;
        }

        $user = $reminder->getUser();
        if ($user === null) {
            return;
        }

        $robotUser = $this->robotUserProvider->getRobotUser() ?? $this->userRepository->findOneBy([]);
        if ($robotUser === null) {
            return;
        }

        $deliveryChannel = $this->resolveDeliveryChannel($reminder, $user, $robotUser);
        $reminderText = $this->buildReminderText($reminder, $user);

        if ($deliveryChannel !== null) {
            $this->messagePublishService->publish(
                channel: $deliveryChannel,
                currentUser: $robotUser,
                messageText: $reminderText,
            );
        }

        $reminder->setStatus('delivered');
        $this->reminderRepository->save($reminder);
    }

    private function resolveDeliveryChannel(Reminder $reminder, User $user, User $robotUser): ?Channel
    {
        $channel = $reminder->getChannel();
        if ($reminder->getTargetMessage() === null || $this->dmManager === null) {
            return $channel;
        }

        try {
            return $this->dmManager->getOrCreateDm($user, $robotUser);
        } catch (\Throwable) {
            return $channel;
        }
    }

    private function buildReminderText(Reminder $reminder, User $user): string
    {
        $targetMessage = $reminder->getTargetMessage();
        if ($targetMessage === null) {
            return sprintf('⏰ **Rappel pour @%s** : %s', $user->getUsername(), $reminder->getMessage());
        }

        $authorName = $targetMessage->getAuthor()?->getUsername() ?? 'Inconnu';
        $snippet = mb_substr($targetMessage->getContent() ?? '', 0, 180);
        $sourceChannel = $targetMessage->getChannel();
        $sourceChannelName = $sourceChannel?->getName() ?? 'canal';
        $sourceChannelSlug = $sourceChannel?->getSlug() ?? '';

        return sprintf(
            "⏰ **Rappel** : Vous avez demandé à vous rappeler ce message posté par **@%s** dans #%s :\n> %s\n\n[👉 Voir le message dans #%s](/channels/%s?jumpTo=%d)",
            $authorName,
            $sourceChannelName,
            $snippet,
            $sourceChannelName,
            $sourceChannelSlug,
            $targetMessage->getId(),
        );
    }
}
