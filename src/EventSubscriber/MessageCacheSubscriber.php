<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\PollVote;
use App\Twig\AppExtensionRuntime;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[AsDoctrineListener(Events::postPersist)]
#[AsDoctrineListener(Events::postUpdate)]
#[AsDoctrineListener(Events::postRemove)]
final readonly class MessageCacheSubscriber
{
    public function __construct(
        #[Target('twigCache')]
        private CacheItemPoolInterface $twigCache,
        private AppExtensionRuntime $appExtensionRuntime,
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

            return;
        }

        if ($entity instanceof Channel) {
            $message = $entity->getParentMessage();
            if ($message instanceof Message) {
                $this->invalidate($message);
            }

            return;
        }

        if ($entity instanceof PollVote) {
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
        $this->appExtensionRuntime->resetSubchannelCache();
    }
}
