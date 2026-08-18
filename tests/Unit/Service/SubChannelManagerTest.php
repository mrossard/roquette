<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Service\AuditLoggerService;
use App\Service\ChannelAccessService;
use App\Service\KanbanManager;
use App\Service\SubChannelManager;
use App\Service\UniqueSlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class SubChannelManagerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private ChannelRepository $channelRepository;
    private ChannelAccessService $channelAccessService;
    private UniqueSlugGenerator $slugGenerator;
    private SubChannelManager $subChannelManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->channelRepository = $this->createMock(ChannelRepository::class);
        $this->channelAccessService = $this->createMock(ChannelAccessService::class);
        $this->slugGenerator = $this->createMock(UniqueSlugGenerator::class);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->subChannelManager = new SubChannelManager(
            $this->entityManager,
            $this->channelRepository,
            $this->channelAccessService,
            $this->createStub(AuditLoggerService::class),
            $this->createStub(LoggerInterface::class),
            $translator,
            $this->slugGenerator,
            $this->createStub(KanbanManager::class),
        );
    }

    #[Test]
    public function buildSubChannelsByParentGroupsCorrectly(): void
    {
        $parentChannel = new Channel();
        $ref = new \ReflectionProperty(Channel::class, 'id');
        $ref->setValue($parentChannel, 10);

        $parentMessage = new Message();
        $refMsg = new \ReflectionProperty(Message::class, 'id');
        $refMsg->setValue($parentMessage, 100);
        $parentMessage->setChannel($parentChannel);

        $subChannel1 = new Channel();
        $subChannel1->setParentMessage($parentMessage);

        $subChannel2 = new Channel();
        $subChannel2->setParentMessage($parentMessage);

        $normalChannel = new Channel();

        $grouped = $this->subChannelManager->buildSubChannelsByParent([$subChannel1, $subChannel2, $normalChannel]);

        $this->assertArrayHasKey(10, $grouped);
        $this->assertCount(2, $grouped[10]);
    }

    #[Test]
    public function createSubChannelReturnsExistingIfAlreadyCreated(): void
    {
        $parentMessage = new Message();
        $user = new User();

        $existingSub = new Channel();
        $this->channelRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['parentMessage' => $parentMessage])
            ->willReturn($existingSub);

        $result = $this->subChannelManager->createSubChannel($parentMessage, $user);
        $this->assertSame($existingSub, $result);
    }
}
