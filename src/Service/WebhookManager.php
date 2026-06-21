<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Message;
use App\Entity\User;
use App\Entity\Webhook;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

readonly class WebhookManager
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
        private MessageRenderer $messageRenderer,
        private MercurePublisher $mercurePublisher,
    ) {}

    public function processIncomingWebhook(
        Webhook $webhook,
        string $content,
        ?string $customName = null,
        ?string $customAvatar = null,
    ): Message {
        $robotUser = $this->userRepository->findOneBy(['username' => User::ROBOT_USERNAME]);
        if (!$robotUser) {
            $robotUser = $webhook->getCreator();
        }

        $message = new Message();
        $message->setChannel($webhook->getChannel());
        $message->setAuthor($robotUser);
        $message->setContent(trim($content));

        $resolvedName = $customName ?? $webhook->getName();
        $message->setCustomAuthorName((string) $resolvedName);
        if ($customAvatar !== null) {
            $message->setCustomAuthorAvatar((string) $customAvatar);
        }

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $renderedHtml = $this->messageRenderer->renderFeedItem($message);

        $this->mercurePublisher->publishNewMessage(
            $webhook->getChannel(),
            $message,
            $robotUser,
            $content,
            $renderedHtml,
        );

        return $message;
    }
}
