<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\Channel\ResolvedChannelContext;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Service\DashboardViewBuilder;
use App\Service\MercurePublisher;
use App\Service\MessageFeedContextService;
use App\Service\SidebarDataProvider;
use App\Service\TypingIndicatorService;
use PHPUnit\Framework\TestCase;

final class DashboardViewBuilderTest extends TestCase
{
    public function testBuildChannelViewContextAssemblesFullContext(): void
    {
        $currentUser = new User();
        $channel = new Channel();
        $ref = new \ReflectionProperty(Channel::class, 'id');
        $ref->setValue($channel, 42);

        $resolved = new ResolvedChannelContext($channel, isMember: true);
        $message = new Message();

        $sidebarDataProvider = $this->createMock(SidebarDataProvider::class);
        $sidebarDataProvider->expects(static::once())
            ->method('getSidebarData')
            ->with($currentUser)
            ->willReturn([
                'unreadCounts' => [
                    42 => ['unread' => 0, 'notificationsEnabled' => true],
                ],
                'workspaces' => [],
            ]);

        $feedContextService = $this->createMock(MessageFeedContextService::class);
        $feedContextService->expects(static::once())
            ->method('buildFeedContext')
            ->with($channel, [$message], $currentUser)
            ->willReturn([
                'replyCounts' => [1 => 3],
                'subchannelByParentMessageId' => [],
            ]);

        $typingIndicatorService = $this->createMock(TypingIndicatorService::class);
        $typingIndicatorService->expects(static::once())
            ->method('getTypingUsers')
            ->with($channel, $currentUser)
            ->willReturn(['bob']);

        $mercurePublisher = $this->createMock(MercurePublisher::class);
        $mercurePublisher->expects(static::once())
            ->method('getChannelTopic')
            ->with($channel)
            ->willReturn('https://example.com/hub/topics/channel-42');

        $builder = new DashboardViewBuilder(
            $sidebarDataProvider,
            $feedContextService,
            $typingIndicatorService,
            $mercurePublisher,
        );

        $context = $builder->buildChannelViewContext($currentUser, $resolved, [$message], 10);

        static::assertSame($channel, $context['activeChannel']);
        static::assertSame([$message], $context['messages']);
        static::assertSame('https://example.com/hub/topics/channel-42', $context['topic_url']);
        static::assertSame(10, $context['firstUnreadMessageId']);
        static::assertTrue($context['isMember']);
        static::assertTrue($context['notificationsEnabled']);
        static::assertSame(['bob'], $context['typingUsers']);
        static::assertSame([1 => 3], $context['replyCounts']);
        static::assertSame([], $context['workspaces']);
    }

    public function testBuildChannelViewContextWhenNotMemberDoesNotQueryTypingUsers(): void
    {
        $currentUser = new User();
        $channel = new Channel();
        $resolved = new ResolvedChannelContext($channel, isMember: false);

        $sidebarDataProvider = $this->createMock(SidebarDataProvider::class);
        $sidebarDataProvider->expects(static::once())
            ->method('getSidebarData')
            ->with($currentUser)
            ->willReturn(['unreadCounts' => []]);

        $feedContextService = $this->createMock(MessageFeedContextService::class);
        $feedContextService->expects(static::once())
            ->method('buildFeedContext')
            ->willReturn(['replyCounts' => []]);

        $typingIndicatorService = $this->createMock(TypingIndicatorService::class);
        $typingIndicatorService->expects(static::never())->method('getTypingUsers');

        $mercurePublisher = $this->createStub(MercurePublisher::class);
        $mercurePublisher->method('getChannelTopic')->willReturn('topic');

        $builder = new DashboardViewBuilder(
            $sidebarDataProvider,
            $feedContextService,
            $typingIndicatorService,
            $mercurePublisher,
        );

        $context = $builder->buildChannelViewContext($currentUser, $resolved);

        static::assertFalse($context['isMember']);
        static::assertSame([], $context['typingUsers']);
    }

    public function testBuildKanbanViewContext(): void
    {
        $currentUser = new User();
        $channel = new Channel();
        $ref = new \ReflectionProperty(Channel::class, 'id');
        $ref->setValue($channel, 99);

        $sidebarDataProvider = $this->createMock(SidebarDataProvider::class);
        $sidebarDataProvider->expects(static::once())
            ->method('getSidebarData')
            ->with($currentUser)
            ->willReturn([
                'unreadCounts' => [
                    99 => ['notificationsEnabled' => false],
                ],
            ]);

        $feedContextService = $this->createStub(MessageFeedContextService::class);
        $typingIndicatorService = $this->createStub(TypingIndicatorService::class);
        $mercurePublisher = $this->createStub(MercurePublisher::class);

        $builder = new DashboardViewBuilder(
            $sidebarDataProvider,
            $feedContextService,
            $typingIndicatorService,
            $mercurePublisher,
        );

        $context = $builder->buildKanbanViewContext($currentUser, $channel, ['col1'], ['msg1'], [$currentUser]);

        static::assertSame($channel, $context['activeChannel']);
        static::assertTrue($context['kanbanView']);
        static::assertSame(['col1'], $context['kanbanColumns']);
        static::assertSame(['msg1'], $context['untriagedMessages']);
        static::assertSame([$currentUser], $context['kanbanMembers']);
        static::assertFalse($context['notificationsEnabled']);
    }
}
