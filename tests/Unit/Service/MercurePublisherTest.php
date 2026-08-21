<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Service\MercurePublisher;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
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
    private \Symfony\Contracts\Translation\TranslatorInterface&MockObject $translator;
    private MercurePublisher $publisher;

    protected function setUp(): void
    {
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->translator = $this->createMock(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $this->publisher = new MercurePublisher($this->bus, 'http://test-mercure', $this->translator);
    }

    #[Test]
    public function getPublicChannelsTemplateTopicReturnsCorrectTopic(): void
    {
        $this->assertSame('http://test-mercure/public/{slug}', $this->publisher->getPublicChannelsTemplateTopic());
    }

    #[Test]
    public function getChannelTopicReturnsCorrectTopicForPublicChannel(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getSlug')->willReturn('general-channel');
        $channel->method('isPrivate')->willReturn(false);
        $channel->method('isDm')->willReturn(false);
        $channel->method('isWorkspaceChannel')->willReturn(false);

        $this->assertSame('http://test-mercure/public/general-channel', $this->publisher->getChannelTopic($channel));
    }

    #[Test]
    public function getChannelTopicReturnsCorrectTopicForPrivateChannel(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getSlug')->willReturn('secret-channel');
        $channel->method('isPrivate')->willReturn(true);
        $channel->method('isDm')->willReturn(false);

        $this->assertSame('http://test-mercure/private/secret-channel', $this->publisher->getChannelTopic($channel));
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
    public function getAdminModerationTopicReturnsCorrectTopic(): void
    {
        $this->assertSame('http://test-mercure/admin/moderation', $this->publisher->getAdminModerationTopic());
    }

    #[Test]
    public function publishModerationCountDispatchesUpdate(): void
    {
        $this->bus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(
                static fn(Update $update) => (
                    $update->getTopics() === ['http://test-mercure/admin/moderation']
                    && $update->getData() === json_encode(['type' => 'moderation_count_changed', 'count' => 3])
                    && $update->isPrivate() === false
                    && $update->getType() === 'moderation_count_changed'
                ),
            ))
            ->willReturn(new Envelope(new \stdClass()));

        $this->publisher->publishModerationCount(3);
    }

    #[Test]
    public function publishToChannelDispatchesUpdateToPublicTopicForPublicChannel(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getSlug')->willReturn('general-channel');
        $channel->method('isPrivate')->willReturn(false);
        $channel->method('isDm')->willReturn(false);
        $channel->method('isWorkspaceChannel')->willReturn(false);

        $payload = ['foo' => 'bar'];

        $this->bus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(
                static fn(Update $update) => (
                    $update->getTopics() === ['http://test-mercure/public/general-channel']
                    && $update->getData() === json_encode($payload)
                    && $update->isPrivate() === false
                ),
            ))
            ->willReturn(new Envelope(new \stdClass()));

        $this->publisher->publishToChannel($channel, $payload);
    }

    #[Test]
    public function publishToChannelDispatchesToMemberTopicsForPrivateChannel(): void
    {
        $user1 = $this->createMock(User::class);
        $user1->method('getUsername')->willReturn('alice');

        $user2 = $this->createMock(User::class);
        $user2->method('getUsername')->willReturn('bob');

        $channel = $this->createMock(Channel::class);
        $channel->method('getSlug')->willReturn('dm-alice-bob');
        $channel->method('isPrivate')->willReturn(true);
        $channel->method('isDm')->willReturn(true);
        $channel->method('getMembers')->willReturn(new ArrayCollection([$user1, $user2]));

        $payload = ['foo' => 'bar'];

        $dispatchedTopics = [];
        $this->bus
            ->expects($this->exactly(2))
            ->method('dispatch')
            ->with($this->callback(static function (Update $update) use (&$dispatchedTopics, $payload) {
                $dispatchedTopics[] = $update->getTopics()[0];
                return $update->getData() === json_encode($payload) && $update->isPrivate() === true;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->publisher->publishToChannel($channel, $payload);

        $this->assertSame(
            [
                'http://test-mercure/users/alice',
                'http://test-mercure/users/bob',
            ],
            $dispatchedTopics,
        );
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
        $author = $this->createStub(User::class);
        $author->method('getId')->willReturn(1);
        $author->method('getUsername')->willReturn('author-user');
        $author->method('getDisplayName')->willReturn('Author Display Name');

        $memberUser = $this->createStub(User::class);
        $memberUser->method('getId')->willReturn(2);
        $memberUser->method('getUsername')->willReturn('member-user');
        $memberUser->method('getDisplayName')->willReturn('Member Display Name');

        $channel = $this->createStub(Channel::class);
        $channel->method('getSlug')->willReturn('my-channel');
        $channel->method('isPrivate')->willReturn(false);
        $channel->method('isDm')->willReturn(false);
        $channel->method('getName')->willReturn('general');
        $channel->method('getMembers')->willReturn(new ArrayCollection([$author, $memberUser]));

        $message = $this->createStub(Message::class);
        $message->method('getId')->willReturn(99);
        $message->method('getContent')->willReturn('Hello @member-user code check');

        // dispatch: channel HTML, channel notification, async ChannelNotificationMessage
        $this->bus
            ->expects($this->exactly(3))
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

    #[Test]
    public function publishToChannelDirectlyViaHubWhenProvided(): void
    {
        $hub = $this->createMock(\Symfony\Component\Mercure\HubInterface::class);
        $publisher = new MercurePublisher($this->bus, 'http://test-mercure', $this->translator, $hub);

        $channel = $this->createMock(Channel::class);
        $channel->method('getSlug')->willReturn('general-channel');
        $channel->method('isPrivate')->willReturn(false);
        $channel->method('isWorkspaceChannel')->willReturn(false);

        $hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(
                static fn(Update $update) => (
                    $update->getTopics() === ['http://test-mercure/public/general-channel']
                    && $update->getData() === 'test-data'
                ),
            ));
        $this->bus->expects($this->never())->method('dispatch');

        $publisher->publishToChannel($channel, 'test-data');
    }
}
