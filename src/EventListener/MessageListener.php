<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Message;
use App\Service\MessageFormatter;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: Message::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: Message::class)]
class MessageListener
{
    public function __construct(
        private readonly MessageFormatter $formatter,
    ) {}

    public function prePersist(Message $message, LifecycleEventArgs $event): void
    {
        $this->formatMessage($message);
    }

    public function preUpdate(Message $message, LifecycleEventArgs $event): void
    {
        $this->formatMessage($message);
    }

    private function formatMessage(Message $message): void
    {
        $content = $message->getContent();
        if ($content === null || $content === '') {
            $message->setFormattedContent('');
            return;
        }

        if (str_starts_with($content, '/me ') || $content === '/me') {
            $content = $content === '/me' ? '' : substr($content, 4);
        }

        $message->setFormattedContent($this->formatter->format($content));
    }
}
