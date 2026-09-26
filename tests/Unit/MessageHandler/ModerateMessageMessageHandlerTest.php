<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Channel;
use App\Entity\Message;
use App\Message\ModerateMessageMessage;
use App\MessageHandler\ModerateMessageMessageHandler;
use App\Repository\MessageRepository;
use App\Service\ContentModerationService;
use App\Service\MessageBroadcaster;
use App\Service\ModerationResult;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class ModerateMessageMessageHandlerTest extends TestCase
{
    public function testInvokeMasksSecretAndPublishesMercure(): void
    {
        $messageRepository = $this->createMock(MessageRepository::class);
        $moderationService = $this->createMock(ContentModerationService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $messageBroadcaster = $this->createMock(MessageBroadcaster::class);

        $channel = new Channel();
        $channel->setSlug('general');

        $messageEntity = new Message();
        $messageEntity->setContent('Clé sk-proj-12345678901234567890123');
        $messageEntity->setChannel($channel);

        $messageRepository->expects($this->once())->method('find')->with(42)->willReturn($messageEntity);

        $moderationResult = ModerationResult::masked(
            maskedContent: 'Clé [SECRET MASQUÉ]',
            originalContent: 'Clé sk-proj-12345678901234567890123',
            reason: 'Clé d\'API OpenAI',
        );

        $moderationService
            ->expects($this->once())
            ->method('moderate')
            ->with('Clé sk-proj-12345678901234567890123', true)
            ->willReturn($moderationResult);

        $em->expects($this->once())->method('flush');

        $messageBroadcaster->expects($this->once())->method('broadcastMessageUpdate')->with($messageEntity);

        $messageBroadcaster->expects($this->once())->method('publishCurrentModerationCount');

        $handler = new ModerateMessageMessageHandler(
            $messageRepository,
            $moderationService,
            $em,
            $messageBroadcaster,
            new NullLogger(),
        );

        $handler(new ModerateMessageMessage(42));

        static::assertSame('masked', $messageEntity->getModerationStatus());
        static::assertSame('Clé [SECRET MASQUÉ]', $messageEntity->getContent());
        static::assertSame('Clé sk-proj-12345678901234567890123', $messageEntity->getOriginalContent());
    }

    public function testInvokePassesChannelAiModerationDisabled(): void
    {
        $messageRepository = $this->createMock(MessageRepository::class);
        $moderationService = $this->createMock(ContentModerationService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $messageBroadcaster = $this->createMock(MessageBroadcaster::class);

        $channel = new Channel();
        $channel->setSlug('general');
        $channel->setAiModerationEnabled(false);

        $messageEntity = new Message();
        $messageEntity->setContent('Contenu normal');
        $messageEntity->setChannel($channel);

        $messageRepository->expects($this->once())->method('find')->with(43)->willReturn($messageEntity);

        $moderationService
            ->expects($this->once())
            ->method('moderate')
            ->with('Contenu normal', false)
            ->willReturn(ModerationResult::clean());

        $em->expects($this->once())->method('flush');

        $handler = new ModerateMessageMessageHandler(
            $messageRepository,
            $moderationService,
            $em,
            $messageBroadcaster,
            new NullLogger(),
        );

        $handler(new ModerateMessageMessage(43));

        static::assertSame('clean', $messageEntity->getModerationStatus());
    }

    public function testInvokeSkipsDmMessages(): void
    {
        $messageRepository = $this->createMock(MessageRepository::class);
        $moderationService = $this->createMock(ContentModerationService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $messageBroadcaster = $this->createMock(MessageBroadcaster::class);

        $channel = new Channel();
        $channel->setSlug('dm-user1-user2');
        $channel->setIsDm(true);

        $messageEntity = new Message();
        $messageEntity->setContent('Secret personnel en DM: sk-proj-12345678901234567890');
        $messageEntity->setChannel($channel);

        $messageRepository->expects($this->once())->method('find')->with(99)->willReturn($messageEntity);

        $moderationService->expects($this->never())->method('moderate');
        $messageBroadcaster->expects($this->never())->method('broadcastMessageUpdate');
        $messageBroadcaster->expects($this->never())->method('publishCurrentModerationCount');

        $handler = new ModerateMessageMessageHandler(
            $messageRepository,
            $moderationService,
            $em,
            $messageBroadcaster,
            new NullLogger(),
        );

        $handler(new ModerateMessageMessage(99));

        static::assertNull($messageEntity->getModerationStatus());
    }

    public function testInvokeBroadcastsUpdatesForRepliesWhenModerated(): void
    {
        $messageRepository = $this->createMock(MessageRepository::class);
        $moderationService = $this->createMock(ContentModerationService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $messageBroadcaster = $this->createMock(MessageBroadcaster::class);

        $channel = new Channel();
        $channel->setSlug('general');

        $messageEntity = new Message();
        $messageEntity->setContent('Message toxique avec insultes');
        $messageEntity->setChannel($channel);

        $reply1 = new Message();
        $reply1->setContent('Réponse 1');
        $reply1->setChannel($channel);
        $reply1->setParentMessage($messageEntity);
        $messageEntity->getReplies()->add($reply1);

        $reply2 = new Message();
        $reply2->setContent('Réponse 2');
        $reply2->setChannel($channel);
        $reply2->setParentMessage($messageEntity);
        $messageEntity->getReplies()->add($reply2);

        $messageRepository->expects($this->once())->method('find')->with(50)->willReturn($messageEntity);

        $moderationResult = ModerationResult::flagged('Contenu toxique');

        $moderationService
            ->expects($this->once())
            ->method('moderate')
            ->with('Message toxique avec insultes', true)
            ->willReturn($moderationResult);

        $em->expects($this->once())->method('flush');

        $updatedMessages = [];
        $messageBroadcaster
            ->expects($this->exactly(3))
            ->method('broadcastMessageUpdate')
            ->willReturnCallback(static function (Message $msg) use (&$updatedMessages): void {
                $updatedMessages[] = $msg;
            });

        $messageBroadcaster->expects($this->once())->method('publishCurrentModerationCount');

        $handler = new ModerateMessageMessageHandler(
            $messageRepository,
            $moderationService,
            $em,
            $messageBroadcaster,
            new NullLogger(),
        );

        $handler(new ModerateMessageMessage(50));

        static::assertSame('flagged', $messageEntity->getModerationStatus());
        static::assertCount(3, $updatedMessages);
        static::assertSame($messageEntity, $updatedMessages[0]);
        static::assertSame($reply1, $updatedMessages[1]);
        static::assertSame($reply2, $updatedMessages[2]);
    }
}
