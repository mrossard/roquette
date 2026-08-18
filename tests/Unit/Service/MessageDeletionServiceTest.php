<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Service\FileUploadService;
use App\Service\MessageBroadcaster;
use App\Service\MessageDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class MessageDeletionServiceTest extends TestCase
{
    private MessageRepository $messageRepo;
    private EntityManagerInterface $em;
    private MessageBroadcaster $broadcaster;
    private FileUploadService $fileUploadService;
    private TranslatorInterface $translator;
    private MessageDeletionService $service;

    protected function setUp(): void
    {
        $this->messageRepo = $this->createMock(MessageRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->broadcaster = $this->createMock(MessageBroadcaster::class);
        $this->fileUploadService = $this->createMock(FileUploadService::class);
        $this->translator = $this->createStub(TranslatorInterface::class);

        $this->service = new MessageDeletionService(
            $this->messageRepo,
            $this->em,
            $this->broadcaster,
            $this->fileUploadService,
            $this->translator,
        );
    }

    #[Test]
    public function deleteThrowsAccessDeniedWhenUserNotAllowed(): void
    {
        $author = new User();
        $creator = new User();
        $stranger = new User();
        $stranger->setRoles(['ROLE_USER']);

        $channel = new Channel();
        $channel->setCreator($creator);

        $message = new Message();
        $message->setAuthor($author);
        $message->setChannel($channel);

        $this->messageRepo->expects($this->once())->method('find')->with(5)->willReturn($message);

        $this->expectException(AccessDeniedHttpException::class);
        $this->service->delete(5, $stranger);
    }

    #[Test]
    public function deleteUnpinsDeletesFileAndBroadcasts(): void
    {
        $author = new User();
        $channel = new Channel();
        $channel->setSlug('general');

        $message = new Message();
        $message->setAuthor($author);
        $message->setChannel($channel);
        $message->setFilePath('uploads/doc.pdf');
        $message->setModerationStatus('flagged');

        $channel->setPinnedMessage($message);

        $this->messageRepo->expects($this->once())->method('find')->with(42)->willReturn($message);
        $this->fileUploadService->expects($this->once())->method('delete')->with('uploads/doc.pdf');
        $this->em->expects($this->once())->method('remove')->with($message);
        $this->em->expects($this->once())->method('flush');
        $this->broadcaster->expects($this->once())->method('publishCurrentModerationCount');
        $this->broadcaster
            ->expects($this->once())
            ->method('broadcastMessageDeletion')
            ->with($channel, 42, '<div id="pinned-banner-container" hx-swap-oob="true"></div>');

        $this->service->delete(42, $author);

        $this->assertNull($channel->getPinnedMessage());
    }
}
