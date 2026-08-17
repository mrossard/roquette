<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Service\RobotDmMessageService;
use App\Service\RobotUserProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class RobotDmMessageServiceTest extends TestCase
{
    #[Test]
    public function persistRobotDmMessageReturnsNullWhenNotRobotDmChannel(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $channelRepo = $this->createStub(ChannelRepository::class);
        $messageRepo = $this->createStub(MessageRepository::class);
        $robotUserProvider = $this->createStub(RobotUserProvider::class);

        $robotUserProvider->method('isRobotDmChannel')->willReturn(false);

        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $service = new RobotDmMessageService($em, $channelRepo, $messageRepo, $robotUserProvider);
        $result = $service->persistRobotDmMessage('general', 'Hello world');

        $this->assertNull($result);
    }

    #[Test]
    public function persistRobotDmMessageCreatesAndPersistsMessage(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $channelRepo = $this->createStub(ChannelRepository::class);
        $messageRepo = $this->createStub(MessageRepository::class);
        $robotUserProvider = $this->createStub(RobotUserProvider::class);

        $robotUser = new User();
        $robotUser->setUsername('assistant');

        $channel = new Channel();
        $channel->setSlug('dm-assistant-user');

        $robotUserProvider->method('isRobotDmChannel')->willReturn(true);
        $robotUserProvider->method('getRobotUser')->willReturn($robotUser);
        $channelRepo->method('findOneBy')->willReturn($channel);

        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(Message::class));
        $em->expects($this->once())->method('flush');

        $service = new RobotDmMessageService($em, $channelRepo, $messageRepo, $robotUserProvider);
        $result = $service->persistRobotDmMessage('dm-assistant-user', 'Bonjour !');

        $this->assertInstanceOf(Message::class, $result);
        $this->assertSame($robotUser, $result->getAuthor());
        $this->assertSame($channel, $result->getChannel());
        $this->assertSame('Bonjour !', $result->getContent());
    }

    #[Test]
    public function updateOrPersistRobotDmMessageUpdatesExistingRobotMessage(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $channelRepo = $this->createStub(ChannelRepository::class);
        $messageRepo = $this->createStub(MessageRepository::class);
        $robotUserProvider = $this->createStub(RobotUserProvider::class);

        $robotUser = new User();
        $channel = new Channel();
        $channel->setSlug('dm-assistant-user');

        $existingRobotMsg = new Message();
        $existingRobotMsg->setAuthor($robotUser);
        $existingRobotMsg->setContent('Veuillez confirmer...');

        $robotUserProvider->method('isRobotDmChannel')->willReturn(true);
        $robotUserProvider->method('isRobotUser')->willReturn(true);
        $channelRepo->method('findOneBy')->willReturn($channel);
        $messageRepo->method('findLatestInChannel')->willReturn([$existingRobotMsg]);

        $em->expects($this->never())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = new RobotDmMessageService($em, $channelRepo, $messageRepo, $robotUserProvider);
        $service->updateOrPersistRobotDmMessage('dm-assistant-user', 'Action confirmée !');

        $this->assertSame('Action confirmée !', $existingRobotMsg->getContent());
    }

    #[Test]
    public function updateOrPersistRobotDmMessageCreatesNewWhenNoRobotMessageFound(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $channelRepo = $this->createStub(ChannelRepository::class);
        $messageRepo = $this->createStub(MessageRepository::class);
        $robotUserProvider = $this->createStub(RobotUserProvider::class);

        $robotUser = new User();
        $humanUser = new User();
        $channel = new Channel();
        $channel->setSlug('dm-assistant-user');

        $humanMsg = new Message();
        $humanMsg->setAuthor($humanUser);

        $robotUserProvider->method('isRobotDmChannel')->willReturn(true);
        $robotUserProvider->method('isRobotUser')->willReturn(false);
        $robotUserProvider->method('getRobotUser')->willReturn($robotUser);
        $channelRepo->method('findOneBy')->willReturn($channel);
        $messageRepo->method('findLatestInChannel')->willReturn([$humanMsg]);

        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(Message::class));
        $em->expects($this->once())->method('flush');

        $service = new RobotDmMessageService($em, $channelRepo, $messageRepo, $robotUserProvider);
        $service->updateOrPersistRobotDmMessage('dm-assistant-user', 'Nouveau résultat');
    }
}
