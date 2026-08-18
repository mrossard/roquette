<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Group;

use App\Entity\Channel;
use App\Entity\GroupSubscription;
use App\Service\Group\GroupDTO;
use App\Service\Group\GroupProviderInterface;
use App\Service\Group\GroupSubscriptionManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class GroupSubscriptionManagerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private TranslatorInterface $translator;
    private GroupSubscriptionManager $manager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->translator = $this->createStub(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnArgument(0);

        $this->manager = new GroupSubscriptionManager($this->entityManager, $this->translator);
    }

    #[Test]
    public function attachThrowsOnEmptyIdentifier(): void
    {
        $channel = new Channel();

        $this->expectException(InvalidArgumentException::class);
        $this->manager->attachGroupSubscription($channel, '');
    }

    #[Test]
    public function attachThrowsWhenOfficialGroupAlreadyHasChannel(): void
    {
        $channel = new Channel();

        $repo = $this->createMock(EntityRepository::class);
        $repo
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['groupIdentifier' => 'group-tech', 'isGroupChannel' => true])
            ->willReturn(new GroupSubscription());

        $this->entityManager
            ->expects($this->once())
            ->method('getRepository')
            ->with(GroupSubscription::class)
            ->willReturn($repo);

        $this->expectException(InvalidArgumentException::class);
        $this->manager->attachOfficialGroupSubscription($channel, 'group-tech');
    }

    #[Test]
    public function subscribeReturnsNullOnEmptyIdentifier(): void
    {
        $channel = new Channel();
        $this->assertNull($this->manager->subscribe($channel, '   '));
    }

    #[Test]
    public function subscribeReturnsExistingWhenAlreadySubscribed(): void
    {
        $channel = new Channel();
        $existing = new GroupSubscription();
        $existing->setGroupIdentifier('group-sales');

        $repo = $this->createMock(EntityRepository::class);
        $repo
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['channel' => $channel, 'groupIdentifier' => 'group-sales'])
            ->willReturn($existing);

        $this->entityManager
            ->expects($this->once())
            ->method('getRepository')
            ->with(GroupSubscription::class)
            ->willReturn($repo);

        $this->entityManager->expects($this->never())->method('persist');

        $result = $this->manager->subscribe($channel, 'group-sales');
        $this->assertSame($existing, $result);
    }

    #[Test]
    public function subscribePersistsNewSubscription(): void
    {
        $channel = new Channel();

        $repo = $this->createMock(EntityRepository::class);
        $repo
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['channel' => $channel, 'groupIdentifier' => 'group-marketing'])
            ->willReturn(null);

        $this->entityManager
            ->expects($this->once())
            ->method('getRepository')
            ->with(GroupSubscription::class)
            ->willReturn($repo);

        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(GroupSubscription::class));
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->manager->subscribe($channel, 'group-marketing');
        $this->assertNotNull($result);
        $this->assertSame('group-marketing', $result->getGroupIdentifier());
        $this->assertFalse($result->isGroupChannel());
        $this->assertTrue($channel->getGroupSubscriptions()->contains($result));
    }

    #[Test]
    public function unsubscribeReturnsFalseWhenNotFound(): void
    {
        $channel = new Channel();

        $repo = $this->createMock(EntityRepository::class);
        $repo->expects($this->once())->method('find')->with(99)->willReturn(null);

        $this->entityManager
            ->expects($this->once())
            ->method('getRepository')
            ->with(GroupSubscription::class)
            ->willReturn($repo);

        $this->assertFalse($this->manager->unsubscribe($channel, 99));
    }

    #[Test]
    public function unsubscribeReturnsFalseWhenChannelMismatch(): void
    {
        $channel1 = new Channel();
        $channel2 = new Channel();

        $sub = new GroupSubscription();
        $channel2->addGroupSubscription($sub);

        $repo = $this->createMock(EntityRepository::class);
        $repo->expects($this->once())->method('find')->with(12)->willReturn($sub);

        $this->entityManager
            ->expects($this->once())
            ->method('getRepository')
            ->with(GroupSubscription::class)
            ->willReturn($repo);

        $this->assertFalse($this->manager->unsubscribe($channel1, 12));
    }

    #[Test]
    public function unsubscribeRemovesAndFlushes(): void
    {
        $channel = new Channel();
        $sub = new GroupSubscription();
        $channel->addGroupSubscription($sub);

        $repo = $this->createMock(EntityRepository::class);
        $repo->expects($this->once())->method('find')->with(12)->willReturn($sub);

        $this->entityManager
            ->expects($this->once())
            ->method('getRepository')
            ->with(GroupSubscription::class)
            ->willReturn($repo);

        $this->entityManager->expects($this->once())->method('remove')->with($sub);
        $this->entityManager->expects($this->once())->method('flush');

        $this->assertTrue($this->manager->unsubscribe($channel, 12));
        $this->assertFalse($channel->getGroupSubscriptions()->contains($sub));
    }

    #[Test]
    public function getResolvedSubscriptionsResolvesNames(): void
    {
        $channel = new Channel();
        $sub = new GroupSubscription();
        $sub->setGroupIdentifier('group-dev');
        $sub->setIsGroupChannel(true);
        $channel->addGroupSubscription($sub);

        $groupProvider = $this->createMock(GroupProviderInterface::class);
        $groupProvider
            ->expects($this->once())
            ->method('getGroupByIdentifier')
            ->with('group-dev')
            ->willReturn(new GroupDTO('group-dev', 'Développeurs'));

        $resolved = $this->manager->getResolvedSubscriptions($channel, $groupProvider);

        $this->assertCount(1, $resolved);
        $this->assertSame('group-dev', $resolved[0]['identifier']);
        $this->assertSame('Développeurs', $resolved[0]['name']);
        $this->assertTrue($resolved[0]['isGroupChannel']);
    }
}
