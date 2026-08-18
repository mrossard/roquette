<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\GroupSubscription;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Repository\UserGroupRepository;
use App\Repository\UserRepository;
use App\Service\ChannelMembersDataProvider;
use App\Service\Group\GroupDTO;
use App\Service\Group\GroupProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ChannelMembersDataProviderTest extends TestCase
{
    private GroupProviderInterface $groupProvider;
    private EntityManagerInterface $entityManager;
    private UserGroupRepository $userGroupRepository;
    private UserRepository $userRepository;
    private ChannelMembersDataProvider $provider;

    protected function setUp(): void
    {
        $this->groupProvider = $this->createStub(GroupProviderInterface::class);
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->userGroupRepository = $this->createStub(UserGroupRepository::class);
        $this->userRepository = $this->createStub(UserRepository::class);

        $this->entityManager->method('getRepository')->willReturnCallback(fn(string $className) => match ($className) {
            UserGroup::class => $this->userGroupRepository,
            User::class => $this->userRepository,
            default => throw new \InvalidArgumentException("Unexpected class {$className}"),
        });

        $this->provider = new ChannelMembersDataProvider(
            $this->groupProvider,
            $this->entityManager,
        );
    }

    #[Test]
    public function getMembersModalDataWithoutSubscriptions(): void
    {
        $channel = new Channel();
        $channel->setName('general');

        $data = $this->provider->getMembersModalData($channel);

        static::assertSame($channel, $data['activeChannel']);
        static::assertSame([], $data['resolvedSubscriptions']);
        static::assertSame([], $data['groupMembers']);
    }

    #[Test]
    public function getMembersModalDataWithLocalGroup(): void
    {
        $channel = new Channel();
        $channel->setName('dev-team');

        $directMember = new User();
        $this->setEntityId($directMember, 1);
        $directMember->setUsername('alice');
        $directMember->setDisplayName('Alice Direct');
        $channel->addMember($directMember);

        $sub = new GroupSubscription();
        $sub->setGroupIdentifier('devs');
        $sub->setIsGroupChannel(true);
        $channel->addGroupSubscription($sub);

        $groupUser1 = new User();
        $this->setEntityId($groupUser1, 1); // Same id as directMember -> should be skipped in groupMembers
        $groupUser1->setUsername('alice');

        $groupUser2 = new User();
        $this->setEntityId($groupUser2, 2);
        $groupUser2->setUsername('bob');
        $groupUser2->setDisplayName('Bob Dev');

        $localGroup = new UserGroup();
        $localGroup->setGroupIdentifier('devs');
        $localGroup->setName('Developers');
        $localGroup->addMember($groupUser1);
        $localGroup->addMember($groupUser2);

        $this->userGroupRepository->method('findOneBy')
            ->willReturn($localGroup);

        $this->groupProvider->method('getGroupByIdentifier')
            ->willReturn(new GroupDTO('devs', 'Developers'));

        $data = $this->provider->getMembersModalData($channel);

        static::assertSame($channel, $data['activeChannel']);
        static::assertCount(1, $data['resolvedSubscriptions']);
        static::assertSame('devs', $data['resolvedSubscriptions'][0]['identifier']);
        static::assertSame('Developers', $data['resolvedSubscriptions'][0]['name']);
        static::assertTrue($data['resolvedSubscriptions'][0]['isGroupChannel']);

        // Only groupUser2 should be in groupMembers because groupUser1 is already a direct member
        static::assertCount(1, $data['groupMembers']);
        static::assertArrayHasKey(2, $data['groupMembers']);
        static::assertSame($groupUser2, $data['groupMembers'][2]['user']);
        static::assertSame('Developers', $data['groupMembers'][2]['viaGroup']);
        static::assertTrue($data['groupMembers'][2]['isRegistered']);
    }

    #[Test]
    public function getMembersModalDataWithExternalGroup(): void
    {
        $channel = new Channel();
        $channel->setName('marketing');

        $sub = new GroupSubscription();
        $sub->setGroupIdentifier('ldap-marketing');
        $sub->setIsGroupChannel(false);
        $channel->addGroupSubscription($sub);

        $this->userGroupRepository->method('findOneBy')
            ->willReturn(null);

        $this->groupProvider->method('getGroupByIdentifier')
            ->willReturn(new GroupDTO('ldap-marketing', 'Marketing LDAP'));

        $this->groupProvider->method('getGroupMembers')
            ->willReturn(['registered_user', 'unregistered_user']);

        $registeredUser = new User();
        $this->setEntityId($registeredUser, 10);
        $registeredUser->setUsername('registered_user');
        $registeredUser->setDisplayName('Zoé Marketing');

        $this->userRepository->method('findBy')
            ->willReturn([$registeredUser]);

        $data = $this->provider->getMembersModalData($channel);

        static::assertCount(2, $data['groupMembers']);
        static::assertArrayHasKey(10, $data['groupMembers']);
        static::assertArrayHasKey('ext-unregistered_user', $data['groupMembers']);

        static::assertSame('registered_user', $data['groupMembers'][10]['user']->getUsername());
        static::assertTrue($data['groupMembers'][10]['isRegistered']);

        static::assertSame('unregistered_user', $data['groupMembers']['ext-unregistered_user']['username']);
        static::assertFalse($data['groupMembers']['ext-unregistered_user']['isRegistered']);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity, 'id');
        $reflection->setValue($entity, $id);
    }
}
