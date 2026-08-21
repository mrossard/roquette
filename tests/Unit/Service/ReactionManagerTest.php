<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Message;
use App\Entity\Reaction;
use App\Entity\User;
use App\Repository\ReactionRepository;
use App\Service\KanbanManager;
use App\Service\MessageBroadcaster;
use App\Service\ReactionManager;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ReactionManagerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private ReactionRepository $reactionRepository;
    private KanbanManager $kanbanManager;
    private MessageBroadcaster $messageBroadcaster;
    private ReactionManager $manager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->reactionRepository = $this->createMock(ReactionRepository::class);
        $this->kanbanManager = $this->createMock(KanbanManager::class);
        $this->messageBroadcaster = $this->createMock(MessageBroadcaster::class);

        $this->manager = new ReactionManager(
            $this->entityManager,
            $this->reactionRepository,
            $this->kanbanManager,
            $this->messageBroadcaster,
        );
    }

    public function testToggleReactionAddsNewReactionWhenNotExists(): void
    {
        $message = new Message();
        $user = new User();
        $emoji = '👍';

        $this->reactionRepository
            ->expects(static::once())
            ->method('findOneBy')
            ->with(['message' => $message, 'user' => $user, 'emoji' => $emoji])
            ->willReturn(null);

        $this->entityManager
            ->expects(static::once())
            ->method('persist')
            ->with(static::callback(
                static fn(Reaction $r): bool => (
                    $r->getMessage() === $message
                    && $r->getUser() === $user
                    && $r->getEmoji() === $emoji
                ),
            ));

        $this->entityManager->expects(static::once())->method('flush');

        $this->kanbanManager
            ->expects(static::once())
            ->method('syncCompletionFromReaction')
            ->with($message, $user, $emoji);

        $this->messageBroadcaster->expects(static::once())->method('broadcastMessageUpdate')->with($message);

        $this->manager->toggleReaction($message, $user, $emoji);
    }

    public function testToggleReactionRemovesExistingReaction(): void
    {
        $message = new Message();
        $user = new User();
        $emoji = '👍';

        $existingReaction = new Reaction();
        $existingReaction->setMessage($message);
        $existingReaction->setUser($user);
        $existingReaction->setEmoji($emoji);

        $this->reactionRepository
            ->expects(static::once())
            ->method('findOneBy')
            ->with(['message' => $message, 'user' => $user, 'emoji' => $emoji])
            ->willReturn($existingReaction);

        $this->entityManager->expects(static::once())->method('remove')->with($existingReaction);

        $this->entityManager->expects(static::once())->method('flush');

        $this->kanbanManager
            ->expects(static::once())
            ->method('syncCompletionFromReaction')
            ->with($message, $user, $emoji);

        $this->messageBroadcaster->expects(static::once())->method('broadcastMessageUpdate')->with($message);

        $this->manager->toggleReaction($message, $user, $emoji);
    }

    public function testToggleReactionThrowsExceptionWhenEmojiIsTooLong(): void
    {
        $message = new Message();
        $user = new User();
        $tooLongEmoji = str_repeat('a', 17);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Emoji non supporté.');

        $this->manager->toggleReaction($message, $user, $tooLongEmoji);
    }

    public function testToggleReactionThrowsExceptionWhenEmojiIsEmpty(): void
    {
        $message = new Message();
        $user = new User();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Emoji non supporté.');

        $this->manager->toggleReaction($message, $user, '');
    }
}
