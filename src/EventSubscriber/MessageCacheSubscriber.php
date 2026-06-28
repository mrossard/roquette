<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\PollVote;
use App\Twig\AppExtension;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Cache\CacheItemPoolInterface;

#[AsDoctrineListener(Events::postPersist)]
#[AsDoctrineListener(Events::postUpdate)]
#[AsDoctrineListener(Events::postRemove)]
final readonly class MessageCacheSubscriber
{
    public function __construct(
        private CacheItemPoolInterface $twigCache,
        private AppExtension $appExtension,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->handleEvent($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->handleEvent($args->getObject());
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->handleEvent($args->getObject());
    }

    private function handleEvent(object $entity): void
    {
        if ($entity instanceof Message) {
            $this->invalidate($entity);
            $parent = $entity->getParentMessage();
            if ($parent instanceof Message) {
                $this->invalidate($parent);
            }
        } elseif ($entity instanceof Channel) {
            $message = $entity->getParentMessage();
            if ($message instanceof Message) {
                $this->invalidate($message);
            }
        } elseif ($entity instanceof PollVote) {
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
        $this->appExtension->resetSubchannelCache();
    }
}
