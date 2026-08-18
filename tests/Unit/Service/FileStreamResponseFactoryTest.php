<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\ChannelExport;
use App\Entity\Message;
use App\Service\FileStreamResponseFactory;
use App\Service\FileUploadService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class FileStreamResponseFactoryTest extends TestCase
{
    private FileStreamResponseFactory $factory;
    private TranslatorInterface $translator;
    private FileUploadService $fileUploadService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->fileUploadService = $this->createMock(FileUploadService::class);
        $this->factory = new FileStreamResponseFactory($this->translator);
    }

    public function testGetFallbackFileNameRemovesNonAscii(): void
    {
        static::assertSame('rapport.pdf', FileStreamResponseFactory::getFallbackFileName('rapport.pdf'));
        static::assertSame('resume.txt', FileStreamResponseFactory::getFallbackFileName('résumé.txt'));
        static::assertSame('file.png', FileStreamResponseFactory::getFallbackFileName('✨.png'));
    }

    public function testIsUnsafeForInlinePreview(): void
    {
        $safeMessage = new Message();
        $safeMessage->setMimeType('image/png');
        static::assertFalse(FileStreamResponseFactory::isUnsafeForInlinePreview($safeMessage));

        $htmlMessage = new Message();
        $htmlMessage->setMimeType('text/html');
        static::assertTrue(FileStreamResponseFactory::isUnsafeForInlinePreview($htmlMessage));

        $svgMessage = new Message();
        $svgMessage->setMimeType('image/svg+xml');
        static::assertTrue(FileStreamResponseFactory::isUnsafeForInlinePreview($svgMessage));
    }

    public function testGetPreviewContentTypeDowngradesHtmlAndBinary(): void
    {
        $htmlMsg = new Message();
        $htmlMsg->setMimeType('text/html');
        static::assertSame('text/plain', FileStreamResponseFactory::getPreviewContentType($htmlMsg));

        $svgMsg = new Message();
        $svgMsg->setMimeType('image/svg+xml');
        static::assertSame('application/octet-stream', FileStreamResponseFactory::getPreviewContentType($svgMsg));

        $pngMsg = new Message();
        $pngMsg->setMimeType('image/png');
        static::assertSame('image/png', FileStreamResponseFactory::getPreviewContentType($pngMsg));
    }

    public function testCreateMessageFileResponseStream(): void
    {
        $message = new Message();
        $message->setFileName('document.pdf');
        $message->setFilePath('uploads/document.pdf');
        $message->setMimeType('application/pdf');
        $message->setCreatedAt(new \DateTimeImmutable());

        $this->fileUploadService
            ->expects(self::once())
            ->method('exists')
            ->with('uploads/document.pdf')
            ->willReturn(true);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'PDF content');
        rewind($stream);
        $this->fileUploadService
            ->expects(self::once())
            ->method('readStream')
            ->with('uploads/document.pdf')
            ->willReturn($stream);

        $request = new Request();
        $response = $this->factory->createMessageFileResponse(
            $message,
            $request,
            $this->fileUploadService,
            HeaderUtils::DISPOSITION_ATTACHMENT,
        );

        static::assertSame(200, $response->getStatusCode());
        static::assertSame('application/pdf', $response->headers->get('Content-Type'));
        static::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function testCreateAvatarResponseSetsImmutableCache(): void
    {
        $this->fileUploadService->expects(self::once())->method('exists')->with('avatars/pic.jpg')->willReturn(true);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'image data');
        rewind($stream);
        $this->fileUploadService
            ->expects(self::once())
            ->method('readStream')
            ->with('avatars/pic.jpg')
            ->willReturn($stream);

        $response = $this->factory->createAvatarResponse('avatars/pic.jpg', $this->fileUploadService);

        static::assertSame(200, $response->getStatusCode());
        static::assertSame('image/jpeg', $response->headers->get('Content-Type'));
        static::assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        static::assertSame('sandbox', $response->headers->get('Content-Security-Policy'));
    }

    public function testCreateExportDownloadResponse(): void
    {
        $export = new ChannelExport();
        $export->setFileName('export-general.zip');
        $export->setFilePath('exports/export-general.zip');

        $this->fileUploadService
            ->expects(self::once())
            ->method('exists')
            ->with('exports/export-general.zip')
            ->willReturn(true);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'zip archive');
        rewind($stream);
        $this->fileUploadService
            ->expects(self::once())
            ->method('readStream')
            ->with('exports/export-general.zip')
            ->willReturn($stream);

        $response = $this->factory->createExportDownloadResponse($export, $this->fileUploadService);

        static::assertSame(200, $response->getStatusCode());
        static::assertSame('application/zip', $response->headers->get('Content-Type'));
        static::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function testReadTextPreviewNonExistingFileThrows(): void
    {
        $message = new Message();
        $message->setFilePath('uploads/nonexistent.txt');

        $this->fileUploadService
            ->expects(self::once())
            ->method('exists')
            ->with('uploads/nonexistent.txt')
            ->willReturn(false);
        $this->translator->method('trans')->willReturn('Le fichier n\'existe pas.');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $this->factory->readTextPreview($message, $this->fileUploadService);
    }

    public function testReadTextPreviewShortFile(): void
    {
        $message = new Message();
        $message->setFilePath('uploads/sample.txt');

        $this->fileUploadService->expects(self::once())->method('exists')->with('uploads/sample.txt')->willReturn(true);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'Short file content');
        rewind($stream);
        $this->fileUploadService
            ->expects(self::once())
            ->method('readStream')
            ->with('uploads/sample.txt')
            ->willReturn($stream);

        $result = $this->factory->readTextPreview($message, $this->fileUploadService);

        static::assertSame('Short file content', $result);
    }

    public function testReadTextPreviewTruncated(): void
    {
        $message = new Message();
        $message->setFilePath('uploads/long.txt');

        $this->fileUploadService->expects(self::once())->method('exists')->with('uploads/long.txt')->willReturn(true);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, str_repeat('A', 100));
        rewind($stream);
        $this->fileUploadService
            ->expects(self::once())
            ->method('readStream')
            ->with('uploads/long.txt')
            ->willReturn($stream);
        $this->translator->method('trans')->willReturn('Contenu tronqué');

        $result = $this->factory->readTextPreview($message, $this->fileUploadService, 50);

        static::assertStringStartsWith(str_repeat('A', 50), $result);
        static::assertStringContainsString('Contenu tronqué', $result);
    }
}
