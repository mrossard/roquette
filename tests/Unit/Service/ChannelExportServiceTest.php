<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Service\AuditLoggerService;
use App\Service\ChannelExportService;
use App\Service\Export\ArchiveExporterInterface;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AllowMockObjectsWithoutExpectations]
class ChannelExportServiceTest extends TestCase
{
    #[Test]
    public function generateOrchestratesExportProcess(): void
    {
        $fileUploadService = $this->createMock(FileUploadService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $auditLogger = $this->createMock(AuditLoggerService::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $twig = $this->createMock(Environment::class);
        $archiveExporter = $this->createMock(ArchiveExporterInterface::class);

        $channel = new Channel();
        $channel->setName('General');
        $channel->setSlug('general');

        $user = new User();
        $user->setUsername('alice');

        $message = new Message();
        $message->setContent('Hello world');
        $message->setAuthor($user);
        $message->setChannel($channel);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn([$message]);
        $entityManager->method('getRepository')->willReturn($repo);

        $twig->method('render')->willReturn('<html>Rendered</html>');

        $tempArchive = tempnam(sys_get_temp_dir(), 'export_test_');
        file_put_contents($tempArchive, 'Dummy archive content');

        $archiveExporter->method('getExtension')->willReturn('tar');
        $archiveExporter->method('createArchive')->willReturn($tempArchive);

        $fileUploadService->expects(static::once())->method('writeStream');
        $entityManager->expects(static::once())->method('persist');
        $entityManager->expects(static::once())->method('flush');
        $auditLogger->expects(static::once())->method('log');

        $service = new ChannelExportService(
            $fileUploadService,
            $entityManager,
            $auditLogger,
            $translator,
            $twig,
            $archiveExporter,
        );

        $export = $service->generate($channel, $user);

        static::assertSame('general-export.tar', $export->getFileName());
        static::assertSame($user, $export->getExportedBy());
        static::assertSame('General', $export->getChannelName());
    }
}
