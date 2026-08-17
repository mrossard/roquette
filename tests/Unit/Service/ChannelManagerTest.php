<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\UserRepository;
use App\Service\AuditLoggerService;
use App\Service\ChannelAccessService;
use App\Service\ChannelManager;
use App\Service\Group\GroupSubscriptionManager;
use App\Service\KanbanManager;
use App\Service\MercurePublisher;
use App\Service\RobotUserProvider;
use App\Service\UniqueSlugGenerator;
use App\Service\WorkspaceManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class ChannelManagerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private ChannelRepository $channelRepository;
    private TranslatorInterface $translator;
    private RobotUserProvider $robotUserProvider;
    private ChannelManager $channelManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->channelRepository = $this->createMock(ChannelRepository::class);
        $this->translator = $this->createStub(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnArgument(0);

        $userRepository = $this->createStub(UserRepository::class);
        $this->robotUserProvider = new RobotUserProvider($userRepository);

        $this->channelManager = new ChannelManager(
            $this->entityManager,
            $this->channelRepository,
            $this->createStub(ChannelAccessService::class),
            $this->createStub(MercurePublisher::class),
            $this->createStub(AuditLoggerService::class),
            $this->createStub(LoggerInterface::class),
            $this->translator,
            $this->createStub(AuthorizationCheckerInterface::class),
            $this->createStub(UniqueSlugGenerator::class),
            $this->createStub(KanbanManager::class),
            $this->createStub(WorkspaceManager::class),
            $this->createStub(GroupSubscriptionManager::class),
            $this->robotUserProvider,
        );
    }

    #[Test]
    public function generateDmSlugForNormalUsers(): void
    {
        $u1 = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($u1, 5);

        $u2 = new User();
        $ref->setValue($u2, 12);

        $slug1 = $this->channelManager->generateDmSlug($u1, $u2);
        $slug2 = $this->channelManager->generateDmSlug($u2, $u1);

        $this->assertSame('dm-5-12', $slug1);
        $this->assertSame('dm-5-12', $slug2);
    }

    #[Test]
    public function generateDmSlugWithRobotUsesRobotConvention(): void
    {
        $robot = new User();
        $robot->setUsername(User::ROBOT_USERNAME);
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($robot, 99);

        $user = new User();
        $user->setUsername('alice');
        $ref->setValue($user, 42);

        $slug = $this->channelManager->generateDmSlug($user, $robot);
        $this->assertSame('dm-robot-roquette-alice', $slug);

        $slugReversed = $this->channelManager->generateDmSlug($robot, $user);
        $this->assertSame('dm-robot-roquette-alice', $slugReversed);
    }

    #[Test]
    public function getOrCreateDmThrowsExceptionWhenSelfDm(): void
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->channelManager->getOrCreateDm($user, $user);
    }

    #[Test]
    public function getOrCreateDmReturnsExistingChannel(): void
    {
        $u1 = new User();
        $u1->setUsername('alice');
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($u1, 1);

        $u2 = new User();
        $u2->setUsername('bob');
        $ref->setValue($u2, 2);

        $existing = new Channel();
        $existing->setIsDm(true);
        $existing->addMember($u1);
        $existing->addMember($u2);

        $this->channelRepository->expects($this->once())
            ->method('findDmBetween')
            ->with($u1, $u2)
            ->willReturn($existing);

        $this->entityManager->expects($this->never())->method('persist');

        $result = $this->channelManager->getOrCreateDm($u1, $u2);
        $this->assertSame($existing, $result);
    }

    #[Test]
    public function getOrCreateDmCreatesAndPersistsNewChannelWhenNotFound(): void
    {
        $u1 = new User();
        $u1->setUsername('alice');
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($u1, 1);

        $u2 = new User();
        $u2->setUsername('bob');
        $ref->setValue($u2, 2);

        $this->channelRepository->expects($this->once())
            ->method('findDmBetween')
            ->with($u1, $u2)
            ->willReturn(null);

        $this->entityManager->expects($this->once())->method('persist')->with($this->isInstanceOf(Channel::class));
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->channelManager->getOrCreateDm($u1, $u2);

        $this->assertTrue($result->isDm());
        $this->assertTrue($result->isPrivate());
        $this->assertSame('dm-1-2', $result->getSlug());
        $this->assertSame('alice & bob', $result->getName());
        $this->assertTrue($result->getMembers()->contains($u1));
        $this->assertTrue($result->getMembers()->contains($u2));
    }
}
