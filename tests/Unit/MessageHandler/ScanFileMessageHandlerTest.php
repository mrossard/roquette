<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Message;
use App\Message\ScanFileMessage;
use App\MessageHandler\ScanFileMessageHandler;
use App\Repository\MessageRepository;
use App\Service\ClamavService;
use App\Service\FileUploadService;
use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Twig\Environment;

class ScanFileMessageHandlerTest extends TestCase
{
    public function testInvokeProcessesCleanFile(): void
    {
        $messageRepository = $this->createMock(MessageRepository::class);
        $fileUploadService = $this->createMock(FileUploadService::class);
        $clamavService = $this->createMock(ClamavService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $mercurePublisher = $this->createMock(MercurePublisher::class);
        $twig = $this->createMock(Environment::class);
        $logger = $this->createMock(LoggerInterface::class);

        $channel = $this->createMock(\App\Entity\Channel::class);
        $channel->method('getSlug')->willReturn('general');

        $dbMessage = $this->createMock(Message::class);
        $dbMessage->method('getFilePath')->willReturn('test.jpg');
        $dbMessage->method('getFileName')->willReturn('test.jpg');
        $dbMessage->method('getChannel')->willReturn($channel);

        $messageRepository->method('find')->with(42)->willReturn($dbMessage);
        $fileUploadService->method('exists')->with('test.jpg')->willReturn(true);

        $stream = fopen('php://memory', 'r+');
        $fileUploadService->method('readStream')->with('test.jpg')->willReturn($stream);

        $clamavService->method('scanStream')->willReturn(true);

        // Expectations
        $dbMessage->expects($this->once())->method('setVirusScanStatus')->with('clean');
        $em->expects($this->once())->method('flush');

        $twig->method('render')->willReturn('html');
        $mercurePublisher->expects($this->once())->method('publishToChannel');

        $handler = new ScanFileMessageHandler(
            $messageRepository,
            $fileUploadService,
            $clamavService,
            $em,
            $mercurePublisher,
            $twig,
            $logger
        );

        $handler(new ScanFileMessage(42));
    }

    public function testInvokeDeletesInfectedFile(): void
    {
        $messageRepository = $this->createMock(MessageRepository::class);
        $fileUploadService = $this->createMock(FileUploadService::class);
        $clamavService = $this->createMock(ClamavService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $mercurePublisher = $this->createMock(MercurePublisher::class);
        $twig = $this->createMock(Environment::class);
        $logger = $this->createMock(LoggerInterface::class);

        $channel = $this->createMock(\App\Entity\Channel::class);
        $channel->method('getSlug')->willReturn('general');

        $dbMessage = $this->createMock(Message::class);
        $dbMessage->method('getFilePath')->willReturn('virus.jpg');
        $dbMessage->method('getFileName')->willReturn('virus.jpg');
        $dbMessage->method('getChannel')->willReturn($channel);

        $messageRepository->method('find')->with(42)->willReturn($dbMessage);
        $fileUploadService->method('exists')->with('virus.jpg')->willReturn(true);

        $stream = fopen('php://memory', 'r+');
        $fileUploadService->method('readStream')->with('virus.jpg')->willReturn($stream);

        $clamavService->method('scanStream')->willReturn(false);

        // Expectations
        $dbMessage->expects($this->once())->method('setVirusScanStatus')->with('infected');
        $fileUploadService->expects($this->once())->method('delete')->with('virus.jpg');
        $em->expects($this->once())->method('flush');

        $twig->method('render')->willReturn('html');
        $mercurePublisher->expects($this->once())->method('publishToChannel');

        $handler = new ScanFileMessageHandler(
            $messageRepository,
            $fileUploadService,
            $clamavService,
            $em,
            $mercurePublisher,
            $twig,
            $logger
        );

        $handler(new ScanFileMessage(42));
    }
}
