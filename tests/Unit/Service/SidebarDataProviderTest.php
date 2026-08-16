<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Invitation;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\UserChannelRead;
use App\Entity\Workspace;
use App\Repository\ChannelRepository;
use App\Repository\InvitationRepository;
use App\Repository\MessageRepository;
use App\Repository\UserChannelReadRepository;
use App\Repository\WorkspaceRepository;
use App\Service\ChannelManager;
use App\Service\ReadTrackingService;
use App\Service\SidebarDataProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SidebarDataProviderTest extends TestCase
{
    private ChannelRepository&MockObject $channelRepository;
    private WorkspaceRepository&MockObject $workspaceRepository;
    private InvitationRepository&MockObject $invitationRepository;
    private MessageRepository&MockObject $messageRepository;
    private ChannelManager&MockObject $channelManager;
    private EntityManagerInterface&MockObject $entityManager;
    private ReadTrackingService&MockObject $readTrackingService;
    private SidebarDataProvider $provider;

    protected function setUp(): void
    {
        $this->channelRepository = $this->createMock(ChannelRepository::class);
        $this->workspaceRepository = $this->createMock(WorkspaceRepository::class);
        $this->invitationRepository = $this->createMock(InvitationRepository::class);
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->channelManager = $this->createMock(ChannelManager::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->readTrackingService = $this->createMock(ReadTrackingService::class);

        $this->provider = new SidebarDataProvider(
            $this->channelRepository,
            $this->workspaceRepository,
            $this->invitationRepository,
            $this->messageRepository,
            $this->channelManager,
            $this->entityManager,
            $this->readTrackingService,
        );
    }

    #[Test]
    public function getSidebarDataAggregatesAllInformation(): void
    {
        $user = $this->createMock(User::class);

        $ws1 = $this->createMock(Workspace::class);
        $ws1->method('getId')->willReturn(1);

        $channel1 = $this->createMock(Channel::class);
        $channel1->method('getId')->willReturn(10);
        $channel1->method('getWorkspace')->willReturn($ws1);

        $channel2 = $this->createMock(Channel::class);
        $channel2->method('getId')->willReturn(20);
        $channel2->method('getWorkspace')->willReturn(null);

        $channels = [$channel1, $channel2];
        $workspaces = [$ws1];
        $invitation = $this->createMock(Invitation::class);
        $invitations = [$invitation];

        $this->channelRepository
            ->expects($this->once())
            ->method('findAllForUser')
            ->with($user)
            ->willReturn($channels);

        $this->readTrackingService
            ->expects($this->once())
            ->method('ensureUserChannelReads')
            ->with($user, $channels);

        $this->workspaceRepository
            ->expects($this->once())
            ->method('findAllForUser')
            ->with($user)
            ->willReturn($workspaces);

        $this->invitationRepository
            ->expects($this->once())
            ->method('findPendingForUser')
            ->with($user)
            ->willReturn($invitations);

        $unreadCounts = [
            10 => ['count' => 3, 'hasMention' => false, 'notificationsEnabled' => true],
            20 => ['count' => 5, 'hasMention' => true, 'notificationsEnabled' => true],
        ];

        $ucrRepo = $this->createMock(UserChannelReadRepository::class);
        $ucrRepo->expects($this->once())->method('getUnreadCounts')->with($user)->willReturn($unreadCounts);

        $this->entityManager
            ->expects($this->once())
            ->method('getRepository')
            ->with(UserChannelRead::class)
            ->willReturn($ucrRepo);

        $this->channelManager
            ->expects($this->once())
            ->method('buildSubChannelsByParent')
            ->with($channels)
            ->willReturn([]);

        $lastMessage = $this->createMock(Message::class);
        $this->messageRepository
            ->expects($this->once())
            ->method('findLastMessagesForChannels')
            ->with([10, 20])
            ->willReturn([10 => $lastMessage]);

        $data = $this->provider->getSidebarData($user);

        $this->assertSame($channels, $data['channels']);
        $this->assertSame($workspaces, $data['workspaces']);
        $this->assertSame($invitations, $data['pendingInvitations']);
        $this->assertSame($unreadCounts, $data['unreadCounts']);
        $this->assertSame([1 => 3], $data['workspaceUnreadCounts']);
        $this->assertSame([], $data['subChannelsByParent']);
        $this->assertSame([10 => $lastMessage], $data['lastMessages']);
    }

    #[Test]
    public function computeWorkspaceUnreadCountsDoesNotCountDuplicateChannelsTwice(): void
    {
        $ws = $this->createMock(Workspace::class);
        $ws->method('getId')->willReturn(1);

        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('getWorkspace')->willReturn($ws);

        // Same channel duplicated in the array
        $channels = [$channel, $channel];
        $unreadCounts = [
            10 => ['count' => 5, 'hasMention' => false, 'notificationsEnabled' => true],
        ];

        $result = $this->provider->computeWorkspaceUnreadCounts($channels, $unreadCounts);

        $this->assertSame([1 => 5], $result);
    }
}
