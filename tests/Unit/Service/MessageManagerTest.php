<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\Message\EditMessageDto;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Service\MessageDeletionService;
use App\Service\MessageEditor;
use App\Service\MessageManager;
use App\Service\SavedMessageService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class MessageManagerTest extends TestCase
{
    private MessageRepository $messageRepo;
    private MessageEditor $editor;
    private MessageDeletionService $deletionService;
    private SavedMessageService $savedMessageService;
    private TranslatorInterface $translator;
    private MessageManager $manager;

    protected function setUp(): void
    {
        $this->messageRepo = $this->createMock(MessageRepository::class);
        $this->editor = $this->createMock(MessageEditor::class);
        $this->deletionService = $this->createMock(MessageDeletionService::class);
        $this->savedMessageService = $this->createMock(SavedMessageService::class);
        $this->translator = $this->createStub(TranslatorInterface::class);

        $this->manager = new MessageManager(
            $this->messageRepo,
            $this->editor,
            $this->deletionService,
            $this->savedMessageService,
            $this->translator,
        );
    }

    #[Test]
    public function findMessageThrowsNotFoundWhenMissing(): void
    {
        $this->messageRepo->expects($this->once())->method('find')->with(999)->willReturn(null);
        $this->expectException(NotFoundHttpException::class);
        $this->manager->findMessage(999);
    }

    #[Test]
    public function editMessageFormDelegatesToEditor(): void
    {
        $user = new User();
        $msg = new Message();
        $this->editor->expects($this->once())->method('getEditableMessage')->with(1, $user)->willReturn($msg);

        $this->assertSame($msg, $this->manager->editMessageForm(1, $user));
    }

    #[Test]
    public function editMessageDelegatesToEditor(): void
    {
        $user = new User();
        $this->editor->expects($this->once())
            ->method('edit')
            ->with(1, $user, $this->isInstanceOf(EditMessageDto::class))
            ->willReturn('<div>rendered</div>');

        $this->assertSame('<div>rendered</div>', $this->manager->editMessage(1, $user, 'test content'));
    }

    #[Test]
    public function deleteMessageDelegatesToDeletionService(): void
    {
        $user = new User();
        $this->deletionService->expects($this->once())->method('delete')->with(1, $user);

        $result = $this->manager->deleteMessage(1, $user);
        $this->assertSame(['success' => true], $result);
    }

    #[Test]
    public function toggleSaveMessageDelegatesToSavedMessageService(): void
    {
        $user = new User();
        $msg = new Message();
        $this->savedMessageService->expects($this->once())->method('toggleSave')->with(1, $user)->willReturn($msg);

        $this->assertSame($msg, $this->manager->toggleSaveMessage(1, $user));
    }
}
