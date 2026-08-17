<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Message;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;

class RobotDmMessageService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ChannelRepository $channelRepository,
        private readonly MessageRepository $messageRepository,
        private readonly RobotUserProvider $robotUserProvider,
    ) {}

    /**
     * Persists a new message from the robot user in a robot DM channel.
     */
    public function persistRobotDmMessage(string $channelSlug, string $content): ?Message
    {
        if (!$this->robotUserProvider->isRobotDmChannel($channelSlug)) {
            return null;
        }

        $robotUser = $this->robotUserProvider->getRobotUser();
        $channel = $this->channelRepository->findOneBy(['slug' => $channelSlug]);
        if (!$robotUser || !$channel) {
            return null;
        }

        $dbMessage = new Message();
        $dbMessage->setAuthor($robotUser);
        $dbMessage->setChannel($channel);
        $dbMessage->setContent($content);
        $dbMessage->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($dbMessage);
        $this->entityManager->flush();

        return $dbMessage;
    }

    /**
     * Updates the latest robot message (e.g., confirmation prompt) or persists a new message if none found.
     */
    public function updateOrPersistRobotDmMessage(string $channelSlug, string $content): void
    {
        if (!$this->robotUserProvider->isRobotDmChannel($channelSlug)) {
            return;
        }

        $channel = $this->channelRepository->findOneBy(['slug' => $channelSlug]);
        if ($channel === null) {
            return;
        }

        $latestMessages = $this->messageRepository->findLatestInChannel($channel, 5);
        foreach ($latestMessages as $msg) {
            if (!$this->robotUserProvider->isRobotUser($msg->getAuthor())) {
                continue;
            }

            $msg->setContent($content);
            $this->entityManager->flush();
            return;
        }

        $robotUser = $this->robotUserProvider->getRobotUser();
        if ($robotUser !== null) {
            $dbMessage = new Message();
            $dbMessage->setAuthor($robotUser);
            $dbMessage->setChannel($channel);
            $dbMessage->setContent($content);
            $dbMessage->setCreatedAt(new \DateTimeImmutable());
            $this->entityManager->persist($dbMessage);
            $this->entityManager->flush();
        }
    }
}
