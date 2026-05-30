<?php

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\UserChannelRead;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles tracking of read status for users in channels.
 */
class ReadTrackingService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Ensures that a UserChannelRead record exists for each of the given channels for the user.
     *
     * @param User $user
     * @param Channel[] $channels
     */
    public function ensureUserChannelReads(User $user, array $channels): void
    {
        $ucrRepo     = $this->entityManager->getRepository(UserChannelRead::class);
        $messageRepo = $this->entityManager->getRepository(Message::class);

        $existingReads      = $ucrRepo->findBy(['user' => $user]);
        $existingChannelIds = [];
        foreach ($existingReads as $read) {
            $existingChannelIds[$read->getChannel()->getId()] = true;
        }

        $changed = false;
        foreach ($channels as $channel) {
            if (isset($existingChannelIds[$channel->getId()])) { continue; }

$latestMessage = $messageRepo->findOneBy(
                    ['channel' => $channel],
                    ['id' => 'DESC']
                );

                $read = new UserChannelRead();
                $read->setUser($user);
                $read->setChannel($channel);
                $read->setLastReadMessage($latestMessage);

                $this->entityManager->persist($read);
                $changed = true;
        }

        if ($changed) {
            $this->entityManager->flush();
        }
    }

    /**
     * Marks a channel as read by updating the UserChannelRead record with the latest message.
     */
    public function markChannelAsRead(User $user, Channel $channel): void
    {
        $ucrRepo    = $this->entityManager->getRepository(UserChannelRead::class);
        $activeRead = $ucrRepo->findOneBy(['user' => $user, 'channel' => $channel]);

        $latestMessage = $this->entityManager->getRepository(Message::class)->findOneBy(
            ['channel' => $channel],
            ['id' => 'DESC']
        );

        if ($activeRead) {
            $activeRead->setLastReadMessage($latestMessage);
        } else {
            $activeRead = new UserChannelRead();
            $activeRead->setUser($user);
            $activeRead->setChannel($channel);
            $activeRead->setLastReadMessage($latestMessage);
            $this->entityManager->persist($activeRead);
        }
        $this->entityManager->flush();
    }
}
