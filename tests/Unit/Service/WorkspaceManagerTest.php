<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\Workspace\UpdateWorkspaceDto;
use App\Entity\Channel;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\ChannelRepository;
use App\Repository\WorkspaceRepository;
use App\Service\AuditLoggerService;
use App\Service\FileUploadService;
use App\Service\Group\GroupProviderInterface;
use App\Service\MercurePublisher;
use App\Service\UniqueSlugGenerator;
use App\Service\WorkspaceManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class WorkspaceManagerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private WorkspaceRepository $workspaceRepository;
    private ChannelRepository $channelRepository;
    private MercurePublisher $mercurePublisher;
    private FileUploadService $fileUploadService;
    private UniqueSlugGenerator $slugGenerator;
    private WorkspaceManager $workspaceManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->workspaceRepository = $this->createMock(WorkspaceRepository::class);
        $this->channelRepository = $this->createMock(ChannelRepository::class);
        $this->mercurePublisher = $this->createMock(MercurePublisher::class);
        $this->fileUploadService = $this->createMock(FileUploadService::class);
        $this->slugGenerator = $this->createMock(UniqueSlugGenerator::class);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->workspaceManager = new WorkspaceManager(
            $this->entityManager,
            $this->workspaceRepository,
            $this->channelRepository,
            $this->mercurePublisher,
            $this->createStub(AuditLoggerService::class),
            $this->createStub(LoggerInterface::class),
            $translator,
            $this->slugGenerator,
            $this->createStub(GroupProviderInterface::class),
            $this->fileUploadService,
        );
    }

    #[Test]
    public function isUserMemberReturnsTrueForPublicWorkspace(): void
    {
        $workspace = new Workspace();
        $workspace->setIsPublic(true);

        $user = new User();
        $this->assertTrue($this->workspaceManager->isUserMember($workspace, $user));
    }

    #[Test]
    public function isUserMemberReturnsTrueForDirectMember(): void
    {
        $workspace = new Workspace();
        $workspace->setIsPublic(false);

        $user = new User();
        $workspace->addMember($user);

        $this->assertTrue($this->workspaceManager->isUserMember($workspace, $user));
    }

    #[Test]
    public function updateWorkspaceUpdatesFieldsAndSlug(): void
    {
        $workspace = new Workspace();
        $workspace->setName('Old Name');
        $workspace->setSlug('old-name');

        $this->slugGenerator->expects($this->once())
            ->method('generate')
            ->willReturn('new-name');

        $this->entityManager->expects($this->once())->method('flush');

        $dto = new UpdateWorkspaceDto(
            name: 'New Name',
            description: 'New Description',
        );

        $this->workspaceManager->update($workspace, $dto);

        $this->assertSame('New Name', $workspace->getName());
        $this->assertSame('New Description', $workspace->getDescription());
        $this->assertSame('new-name', $workspace->getSlug());
    }

    #[Test]
    public function deleteWorkspaceDeletesAvatarIfPresent(): void
    {
        $creator = new User();
        $creator->setUsername('alice');

        $workspace = new Workspace();
        $workspace->setIsPublic(false);
        $workspace->setAvatarPath('avatars/ws-1.png');

        $this->fileUploadService->expects($this->once())
            ->method('delete')
            ->with('avatars/ws-1.png');

        $this->entityManager->expects($this->once())->method('remove')->with($workspace);
        $this->entityManager->expects($this->once())->method('flush');

        $this->workspaceManager->delete($workspace, $creator);
    }
}
