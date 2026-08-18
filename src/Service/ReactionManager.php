<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Message;
use App\Entity\Reaction;
use App\Entity\User;
use App\Repository\ReactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

class ReactionManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReactionRepository $reactionRepository,
        private readonly KanbanManager $kanbanManager,
        private readonly MessageBroadcaster $messageBroadcaster,
    ) {}

    public function toggleReaction(Message $message, User $user, string $emoji): void
    {
        $emojiLength = mb_strlen($emoji);
        if ($emojiLength < 1 || $emojiLength > 16) {
            throw new InvalidArgumentException('Emoji non supporté.');
        }

        $existingReaction = $this->reactionRepository->findOneBy([
            'message' => $message,
            'user' => $user,
            'emoji' => $emoji,
        ]);

        if ($existingReaction !== null) {
            $this->entityManager->remove($existingReaction);
        }

        if ($existingReaction === null) {
            $reaction = new Reaction();
            $reaction->setMessage($message);
            $reaction->setUser($user);
            $reaction->setEmoji($emoji);
            $this->entityManager->persist($reaction);
        }

        $this->entityManager->flush();

        $this->kanbanManager->syncCompletionFromReaction($message, $user, $emoji);
        $this->messageBroadcaster->broadcastMessageUpdate($message);
    }
}
