<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Channel;
use App\Entity\Message;
use App\Message\ModerateMessageMessage;
use App\MessageHandler\ModerateMessageMessageHandler;
use App\Repository\MessageRepository;
use App\Service\ContentModerationService;
use App\Service\MercurePublisher;
use App\Service\MessageRenderer;
use App\Service\ModerationResult;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ModerateMessageMessageHandlerTest extends TestCase
{
    public function testInvokeMasksSecretAndPublishesMercure(): void
    {
        $messageRepository = $this->createMock(MessageRepository::class);
        $moderationService = $this->createMock(ContentModerationService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $mercurePublisher = $this->createMock(MercurePublisher::class);
        $messageRenderer = $this->createMock(MessageRenderer::class);

        $channel = new Channel();
        $channel->setSlug('general');

        $messageEntity = new Message();
        $messageEntity->setContent('Clé sk-proj-12345678901234567890123');
        $messageEntity->setChannel($channel);

        $messageRepository->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($messageEntity);

        $moderationResult = ModerationResult::masked(
            maskedContent: 'Clé [SECRET MASQUÉ]',
            originalContent: 'Clé sk-proj-12345678901234567890123',
            reason: 'Clé d\'API OpenAI'
        );

        $moderationService->expects($this->once())
            ->method('moderate')
            ->with('Clé sk-proj-12345678901234567890123')
            ->willReturn($moderationResult);

        $em->expects($this->once())->method('flush');

        $messageRenderer->expects($this->once())
            ->method('renderFeedItem')
            ->with($messageEntity, ['oob' => true])
            ->willReturn('<div>Rendered HTML</div>');

        $mercurePublisher->expects($this->once())
            ->method('publishToChannel')
            ->with($channel, '<div>Rendered HTML</div>', 'message_general');

        $handler = new ModerateMessageMessageHandler(
            $messageRepository,
            $moderationService,
            $em,
            $mercurePublisher,
            $messageRenderer,
            new NullLogger()
        );

        $handler(new ModerateMessageMessage(42));

        static::assertSame('masked', $messageEntity->getModerationStatus());
        static::assertSame('Clé [SECRET MASQUÉ]', $messageEntity->getContent());
        static::assertSame('Clé sk-proj-12345678901234567890123', $messageEntity->getOriginalContent());
    }
}


