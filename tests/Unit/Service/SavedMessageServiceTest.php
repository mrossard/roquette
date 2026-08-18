<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Service\ChannelAccessService;
use App\Service\SavedMessageService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class SavedMessageServiceTest extends TestCase
{
    private MessageRepository $messageRepo;
    private EntityManagerInterface $em;
    private ChannelAccessService $accessService;
    private TranslatorInterface $translator;
    private SavedMessageService $service;

    protected function setUp(): void
    {
        $this->messageRepo = $this->createMock(MessageRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->accessService = $this->createMock(ChannelAccessService::class);
        $this->translator = $this->createStub(TranslatorInterface::class);

        $this->service = new SavedMessageService(
            $this->messageRepo,
            $this->em,
            $this->accessService,
            $this->translator,
        );
    }

    #[Test]
    public function toggleSaveThrowsAccessDeniedWhenUserCannotAccessChannel(): void
    {
        $user = new User();
        $channel = new Channel();
        $message = new Message();
        $message->setChannel($channel);

        $this->messageRepo->expects($this->once())->method('find')->with(1)->willReturn($message);
        $this->accessService->expects($this->once())->method('canUserAccess')->with($channel, $user)->willReturn(false);

        $this->expectException(AccessDeniedHttpException::class);
        $this->service->toggleSave(1, $user);
    }

    #[Test]
    public function toggleSaveAddsAndRemovesMessage(): void
    {
        $user = new User();
        $channel = new Channel();
        $message = new Message();
        $message->setChannel($channel);

        $this->messageRepo->expects($this->exactly(2))->method('find')->with(1)->willReturn($message);
        $this->accessService
            ->expects($this->exactly(2))
            ->method('canUserAccess')
            ->with($channel, $user)
            ->willReturn(true);
        $this->em->expects($this->exactly(2))->method('flush');

        // Add
        $res = $this->service->toggleSave(1, $user);
        $this->assertSame($message, $res);
        $this->assertTrue($user->getSavedMessages()->contains($message));

        // Remove
        $res = $this->service->toggleSave(1, $user);
        $this->assertSame($message, $res);
        $this->assertFalse($user->getSavedMessages()->contains($message));
    }
}
