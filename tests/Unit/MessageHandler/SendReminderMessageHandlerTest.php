<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Channel;
use App\Entity\Reminder;
use App\Entity\User;
use App\Message\SendReminderMessage;
use App\MessageHandler\SendReminderMessageHandler;
use App\Repository\ReminderRepository;
use App\Repository\UserRepository;
use App\Service\MessagePublishService;
use App\Service\RobotUserProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class SendReminderMessageHandlerTest extends TestCase
{
    public function testInvokePublishesSingleMessageAndUpdatesStatus(): void
    {
        $reminderRepository = $this->createMock(ReminderRepository::class);
        $userRepository = $this->createMock(UserRepository::class);
        $messagePublishService = $this->createMock(MessagePublishService::class);

        $user = new User();
        $user->setUsername('john');

        $channel = new Channel();
        $channel->setName('general');
        $channel->setSlug('general');

        $reminder = new Reminder();
        $reminder->setUser($user);
        $reminder->setChannel($channel);
        $reminder->setMessage('Finir le rapport');
        $reminder->setStatus('pending');

        $robotUser = new User();
        $robotUser->setUsername('robot-roquette');

        $reminderRepository->expects(self::once())->method('find')->with(42)->willReturn($reminder);

        $robotUserProvider = $this->createMock(RobotUserProvider::class);
        $robotUserProvider->method('getRobotUser')->willReturn($robotUser);

        $messagePublishService
            ->expects(self::once())
            ->method('publish')
            ->with($channel, $robotUser, '⏰ **Rappel pour @john** : Finir le rapport');

        $reminderRepository->expects(self::once())->method('save')->with($reminder, true);

        $handler = new SendReminderMessageHandler($reminderRepository, $userRepository, $messagePublishService, $robotUserProvider);

        $handler(new SendReminderMessage(42));

        static::assertSame('delivered', $reminder->getStatus());
    }

    public function testInvokeIgnoresNonPendingReminder(): void
    {
        $reminderRepository = $this->createMock(ReminderRepository::class);
        $userRepository = $this->createMock(UserRepository::class);
        $messagePublishService = $this->createMock(MessagePublishService::class);

        $reminder = new Reminder();
        $reminder->setStatus('delivered');

        $reminderRepository->expects(self::once())->method('find')->with(42)->willReturn($reminder);

        $messagePublishService->expects(self::never())->method('publish');

        $handler = new SendReminderMessageHandler($reminderRepository, $userRepository, $messagePublishService, $this->createMock(RobotUserProvider::class));

        $handler(new SendReminderMessage(42));
    }
}
