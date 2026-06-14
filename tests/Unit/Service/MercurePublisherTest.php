<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;


use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\UserChannelRead;
use App\Repository\UserChannelReadRepository;
use App\Service\MercurePublisher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
class MercurePublisherTest extends TestCase
{
    private MessageBusInterface&MockObject $bus;
    private UserChannelReadRepository&MockObject $ucrRepo;
    private MercurePublisher $publisher;

    protected function setUp(): void
    {
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->ucrRepo = $this->createMock(UserChannelReadRepository::class);
        $this->publisher = new MercurePublisher($this->bus, 'http://test-mercure', $this->ucrRepo);
    }

    #[Test]
    public function getChannelTopicReturnsCorrectTopic(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getSlug')->willReturn('general-channel');

        $this->assertSame('http://test-mercure/channels/general-channel', $this->publisher->getChannelTopic($channel));
    }

    #[Test]
    public function getUserTopicReturnsCorrectTopic(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getUsername')->willReturn('testuser');

        $this->assertSame('http://test-mercure/users/testuser', $this->publisher->getUserTopic($user));
    }

    #[Test]
    public function getStatusTopicReturnsCorrectTopic(): void
    {
        $this->assertSame('http://test-mercure/users/status', $this->publisher->getStatusTopic());
    }

    #[Test]
    public function publishToChannelDispatchesUpdate(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getSlug')->willReturn('general-channel');
        $channel->method('isPrivate')->willReturn(true);

        $payload = ['foo' => 'bar'];

        $this->bus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(
                static fn(Update $update) => (
                    $update->getTopics() === ['http://test-mercure/channels/general-channel']
                    && $update->getData() === json_encode($payload)
                    && $update->isPrivate() === true
                ),
            ))
            ->willReturn(new Envelope(new \stdClass()));

        $this->publisher->publishToChannel($channel, $payload);
    }

    #[Test]
    public function publishToUserDispatchesPrivateUpdate(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getUsername')->willReturn('testuser');

        $payload = ['msg' => 'hello'];

        $this->bus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(
                static fn(Update $update) => (
                    $update->getTopics() === ['http://test-mercure/users/testuser']
                    && $update->getData() === json_encode($payload)
                    && $update->isPrivate() === true
                ),
            ))
            ->willReturn(new Envelope(new \stdClass()));

        $this->publisher->publishToUser($user, $payload);
    }

    #[Test]
    public function publishNewMessageDispatchesToChannelAndMembers(): void
    {
        $channel = $this->createStub(Channel::class);
        $channel->method('getSlug')->willReturn('my-channel');
        $channel->method('isPrivate')->willReturn(false);
        $channel->method('isDm')->willReturn(false);
        $channel->method('getName')->willReturn('general');

        $author = $this->createStub(User::class);
        $author->method('getId')->willReturn(1);
        $author->method('getUsername')->willReturn('author-user');
        $author->method('getDisplayName')->willReturn('Author Display Name');

        $member = $this->createStub(User::class);
        $member->method('getId')->willReturn(2);
        $member->method('getUsername')->willReturn('member-user');
        $member->method('getDisplayName')->willReturn('Member Display Name');
        $member->method('isMentionNotificationsEnabled')->willReturn(true);

        $members = new \Doctrine\Common\Collections\ArrayCollection([$author, $member]);
        $channel
            ->method('getMembers')
            ->willReturn($members);

        $message = $this->createStub(Message::class);
        $message->method('getId')->willReturn(99);
        $message->method('getContent')->willReturn('Hello @member-user code check');

        $ucr = $this->createStub(UserChannelRead::class);
        $ucr->method('isNotificationsEnabled')->willReturn(true);
        $this->ucrRepo
            ->expects($this->once())
            ->method('findByChannelAndUsers')
            ->with($channel, $members)
            ->willReturn([2 => $ucr]);

        // We expect dispatch to be called twice: once for the channel update, once for the member notification.
        $this->bus
            ->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $this->publisher->publishNewMessage(
            $channel,
            $message,
            $author,
            'Hello @member-user code check',
            '<p>Hello @member-user code check</p>',
        );
    }
}
