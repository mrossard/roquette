<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Repository\UserChannelReadRepository;
use App\Service\LinkPreviewService;
use App\Service\MercurePublisher;
use App\Twig\AppExtensionRuntime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class AppExtensionRuntimeTest extends TestCase
{
    private AppExtensionRuntime $runtime;
    private ChannelRepository $channelRepository;
    private UserChannelReadRepository $ucrRepository;
    private EntityManagerInterface $entityManager;
    private LinkPreviewService $linkPreviewService;
    private MessageRepository $messageRepository;

    protected function setUp(): void
    {
        $this->channelRepository = $this->createMock(ChannelRepository::class);
        $this->ucrRepository = $this->createMock(UserChannelReadRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->linkPreviewService = $this->createMock(LinkPreviewService::class);
        $this->messageRepository = $this->createMock(MessageRepository::class);

        $translator = $this->createMock(TranslatorInterface::class);
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $mercurePublisher = new MercurePublisher($bus, 'roquette', $translator);

        $this->runtime = new AppExtensionRuntime(
            $this->linkPreviewService,
            $this->channelRepository,
            $this->ucrRepository,
            $this->entityManager,
            $mercurePublisher,
            $this->messageRepository,
        );
    }

    #[Test]
    public function getSubchannelDoesNotCrashWithoutManagedMessages(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(1);

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getIdentityMap')->willReturn([Message::class => [$message]]);

        $this->entityManager->method('getUnitOfWork')->willReturn($uow);

        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getResult')->willReturn([]);
        $queryBuilder = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $this->channelRepository->method('createQueryBuilder')->willReturn($queryBuilder);

        static::assertNull($this->runtime->getSubchannel($message));
    }

    #[Test]
    public function getSubchannelReturnsNullWhenMessageIdIsNull(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(null);

        static::assertNull($this->runtime->getSubchannel($message));
    }

    #[Test]
    public function getUserMercureTopicsIncludesAdminModerationTopicForAdmins(): void
    {
        $adminUser = $this->createMock(User::class);
        $adminUser->method('getUsername')->willReturn('admin');
        $adminUser->method('getRoles')->willReturn(['ROLE_USER', 'ROLE_ADMIN']);

        $this->channelRepository->method('findAllForUser')->willReturn([]);

        $topics = $this->runtime->getUserMercureTopics($adminUser);

        static::assertContains('roquette/users/admin', $topics);
        static::assertContains('roquette/users/status', $topics);
        static::assertContains('roquette/admin/moderation', $topics);
    }

    #[Test]
    public function getUserMercureTopicsExcludesAdminModerationTopicForStandardUsers(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getUsername')->willReturn('regular');
        $user->method('getRoles')->willReturn(['ROLE_USER']);

        $this->channelRepository->method('findAllForUser')->willReturn([]);

        $topics = $this->runtime->getUserMercureTopics($user);

        static::assertContains('roquette/users/regular', $topics);
        static::assertContains('roquette/users/status', $topics);
        static::assertNotContains('roquette/admin/moderation', $topics);
    }

    #[Test]
    public function getUserChannelNotificationsMapReturnsExpectedMap(): void
    {
        $user = $this->createMock(User::class);

        $channel1 = $this->createMock(Channel::class);
        $channel1->method('getId')->willReturn(1);
        $channel1->method('getSlug')->willReturn('general');
        $channel1->method('isDm')->willReturn(false);

        $channel2 = $this->createMock(Channel::class);
        $channel2->method('getId')->willReturn(2);
        $channel2->method('getSlug')->willReturn('dm-1-2');
        $channel2->method('isDm')->willReturn(true);

        $this->channelRepository->method('findAllForUser')->willReturn([$channel1, $channel2]);
        $this->ucrRepository->method('getUnreadCounts')->willReturn([
            1 => ['notificationsEnabled' => true],
            2 => ['notificationsEnabled' => false],
        ]);

        $map = $this->runtime->getUserChannelNotificationsMap($user);

        static::assertSame([
            'general' => true,
            'dm-1-2' => false,
        ], $map);
    }

    #[Test]
    public function getPendingModerationCountDelegatesToRepository(): void
    {
        $this->messageRepository->method('countPendingModeration')->willReturn(5);

        static::assertSame(5, $this->runtime->getPendingModerationCount());
    }

    #[Test]
    public function getCachedLinkPreviewDelegatesToService(): void
    {
        $this->linkPreviewService->expects(self::once())->method('getCachedPreview')->with('https://example.com')->willReturn(['title' => 'Example']);

        static::assertSame(['title' => 'Example'], $this->runtime->getCachedLinkPreview('https://example.com'));
    }
}
