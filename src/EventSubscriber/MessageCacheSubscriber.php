<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Cache\CacheItemPoolInterface;

#[AsDoctrineListener(Events::postPersist)]
#[AsDoctrineListener(Events::postUpdate)]
#[AsDoctrineListener(Events::postRemove)]
final class MessageCacheSubscriber
{
    public function __construct(
        private readonly CacheItemPoolInterface $twigCache,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->handleEvent($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->handleEvent($args->getObject());
    }

    public function postRemove(\Doctrine\ORM\Event\PostRemoveEventArgs $args): void
    {
        $this->handleEvent($args->getObject());
    }

    private function handleEvent(object $entity): void
    {
        if ($entity instanceof Message) {
            $this->invalidate($entity);
        } elseif ($entity instanceof \App\Entity\PollVote) {
            $message = $entity->getOption()?->getPoll()?->getMessage();
            if ($message instanceof Message) {
                $this->invalidate($message);
            }
        }
    }

    private function invalidate(Message $message): void
    {
        $id = $message->getId();
        if ($id === null) {
            return;
        }

        $this->twigCache->deleteItem('feed_item_body_' . $id);
        $this->twigCache->deleteItem('feed_item_todo_' . $id);
    }
}

