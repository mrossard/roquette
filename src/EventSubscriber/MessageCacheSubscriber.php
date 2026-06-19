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
final class MessageCacheSubscriber
{
    public function __construct(
        private readonly CacheItemPoolInterface $twigCache,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Message) {
            return;
        }

        $this->invalidate($entity);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Message) {
            return;
        }

        $this->invalidate($entity);
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
