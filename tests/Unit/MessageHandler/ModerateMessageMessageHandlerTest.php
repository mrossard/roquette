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
            ->with('Clé sk-proj-12345678901234567890123')
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
}
