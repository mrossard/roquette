<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\Channel\CreateChannelDto;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Service\AuditLoggerService;
use App\Service\ChannelManager;
use App\Service\Group\GroupSubscriptionManager;
use App\Service\KanbanManager;
use App\Service\MercurePublisher;
use App\Service\MessageBroadcaster;
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
    private UniqueSlugGenerator $slugGenerator;
    private MessageBroadcaster $messageBroadcaster;
    private ChannelManager $channelManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->channelRepository = $this->createMock(ChannelRepository::class);
        $this->slugGenerator = $this->createMock(UniqueSlugGenerator::class);
        $this->messageBroadcaster = $this->createMock(MessageBroadcaster::class);
        $this->translator = $this->createStub(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnArgument(0);

        $this->channelManager = new ChannelManager(
            $this->entityManager,
            $this->channelRepository,
            $this->createStub(MercurePublisher::class),
            $this->createStub(AuditLoggerService::class),
            $this->createStub(LoggerInterface::class),
            $this->translator,
            $this->createStub(AuthorizationCheckerInterface::class),
            $this->slugGenerator,
            $this->createStub(KanbanManager::class),
            $this->createStub(WorkspaceManager::class),
            $this->createStub(GroupSubscriptionManager::class),
            $this->messageBroadcaster,
        );
    }

    #[Test]
    public function createChannelPersistsAndReturnsNewChannel(): void
    {
        $user = new User();
        $user->setUsername('alice');

        $this->slugGenerator->expects($this->once())->method('generate')->willReturn('general');

        $this->entityManager->expects($this->once())->method('persist')->with($this->isInstanceOf(Channel::class));
        $this->entityManager->expects($this->once())->method('flush');

        $dto = new CreateChannelDto(name: 'General', description: 'General discussion', isPrivate: false);

        $channel = $this->channelManager->create($dto, $user);

        $this->assertSame('General', $channel->getName());
        $this->assertSame('general', $channel->getSlug());
        $this->assertFalse($channel->isPrivate());
        $this->assertSame($user, $channel->getCreator());
    }

    #[Test]
    public function findChannelBySlugReturnsChannelWhenFound(): void
    {
        $channel = new Channel();
        $channel->setSlug('test-channel');

        $this->channelRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['slug' => 'test-channel'])
            ->willReturn($channel);

        $result = $this->channelManager->findChannelBySlug('test-channel');
        $this->assertSame($channel, $result);
    }

    #[Test]
    public function pinMessageSetsPinnedAndBroadcasts(): void
    {
        $channel = new Channel();
        $message = new Message();
        $message->setChannel($channel);

        $this->entityManager->expects($this->once())->method('flush');

        $this->messageBroadcaster
            ->expects($this->once())
            ->method('broadcastPin')
            ->with($channel, $message, null, '<div class="banner"></div>');

        $this->channelManager->pinMessage($message, '<div class="banner"></div>');

        $this->assertSame($message, $channel->getPinnedMessage());
    }

    #[Test]
    public function unpinMessageResetsPinnedAndBroadcasts(): void
    {
        $channel = new Channel();
        $message = new Message();
        $message->setChannel($channel);
        $channel->setPinnedMessage($message);

        $this->entityManager->expects($this->once())->method('flush');

        $this->messageBroadcaster->expects($this->once())->method('broadcastUnpin')->with($channel, $message);

        $this->channelManager->unpinMessage($message);

        $this->assertNull($channel->getPinnedMessage());
    }
}
