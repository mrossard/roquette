<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;


use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\UserChannelRead;
use App\Repository\MessageRepository;
use App\Repository\UserChannelReadRepository;
use App\Service\ReadTrackingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ReadTrackingServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private ReadTrackingService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = new ReadTrackingService($this->entityManager);
    }

    #[Test]
    public function ensureUserChannelReadsDoesNothingIfReadsExist(): void
    {
        $user = $this->createMock(User::class);
        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(42);

        $read = $this->createMock(UserChannelRead::class);
        $read->method('getChannel')->willReturn($channel);

        $ucrRepo = $this->createMock(UserChannelReadRepository::class);
        $ucrRepo->expects($this->once())->method('findBy')->with(['user' => $user])->willReturn([$read]);

        $messageRepo = $this->createMock(MessageRepository::class);

        $this->entityManager
            ->method('getRepository')
            ->willReturnMap([
                [UserChannelRead::class, $ucrRepo],
                [Message::class,         $messageRepo],
            ]);

        // We expect persist and flush not to be called since the read already exists
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $this->service->ensureUserChannelReads($user, [$channel]);
    }

    #[Test]
    public function ensureUserChannelReadsCreatesRecordIfNotExist(): void
    {
        $user = $this->createMock(User::class);
        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(42);

        $ucrRepo = $this->createMock(UserChannelReadRepository::class);
        $ucrRepo->method('findBy')->willReturn([]); // No existing reads

        $messageRepo = $this->createMock(MessageRepository::class);
        $latestMessage = $this->createMock(Message::class);
        $messageRepo
            ->expects($this->once())
            ->method('findLastMessagesForChannels')
            ->with([42])
            ->willReturn([42 => $latestMessage]);

        $this->entityManager
            ->method('getRepository')
            ->willReturnMap([
                [UserChannelRead::class, $ucrRepo],
                [Message::class,         $messageRepo],
            ]);

        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->with($this->callback(
                static fn(UserChannelRead $read) => (
                    $read->getUser() === $user
                    && $read->getChannel() === $channel
                    && $read->getLastReadMessage() === $latestMessage
                ),
            ));

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->ensureUserChannelReads($user, [$channel]);
    }

    #[Test]
    public function markChannelAsReadUpdatesExistingRecord(): void
    {
        $user = $this->createStub(User::class);
        $channel = $this->createStub(Channel::class);

        $read = $this->createMock(UserChannelRead::class);

        $ucrRepo = $this->createMock(UserChannelReadRepository::class);
        $ucrRepo
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['user' => $user, 'channel' => $channel])
            ->willReturn($read);

        $messageRepo = $this->createStub(MessageRepository::class);
        $latestMessage = $this->createStub(Message::class);
        $messageRepo->method('findOneBy')->willReturn($latestMessage);

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('getRepository')
            ->willReturnMap([
                [UserChannelRead::class, $ucrRepo],
                [Message::class,         $messageRepo],
            ]);

        $read->expects($this->once())->method('setLastReadMessage')->with($latestMessage);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $this->service->markChannelAsRead($user, $channel);
    }
}
