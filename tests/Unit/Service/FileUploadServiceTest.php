<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\FileUploadService;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[AllowMockObjectsWithoutExpectations]
class FileUploadServiceTest extends TestCase
{
    private FilesystemOperator&MockObject $storage;
    private LoggerInterface&MockObject $logger;
    private \Symfony\Contracts\Translation\TranslatorInterface&MockObject $translator;
    private FileUploadService $service;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(FilesystemOperator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->translator = $this->createMock(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $this->translator
            ->method('trans')
            ->willReturnCallback(static fn($id, $parameters = []) => strtr($id, $parameters));
        $this->service = new FileUploadService($this->storage, $this->logger, $this->translator);
    }

    #[Test]
    public function uploadRejectsUnallowedExtension(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getClientOriginalName')->willReturn('test.exe');
        $file->method('getClientOriginalExtension')->willReturn('exe');
        $file->method('getClientMimeType')->willReturn('application/x-msdownload');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('L\'extension de fichier ".exe" n\'est pas autorisée.');

        $this->service->upload($file);
    }

    #[Test]
    public function uploadFallsBackToExtensionBasedMimeType(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getClientOriginalName')->willReturn('doc.md');
        $file->method('getClientOriginalExtension')->willReturn('md');
        // Content-based detection incorrectly returns text/javascript
        $file->method('getMimeType')->willReturn('text/javascript');
        $file->method('getSize')->willReturn(1024);
        $file->method('getPathname')->willReturn(__FILE__);

        $this->storage->expects($this->once())->method('writeStream');

        $result = $this->service->upload($file);

        $this->assertSame('text/markdown', $result['mimeType']);
    }

    #[Test]
    public function uploadAcceptsJsonFiles(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getClientOriginalName')->willReturn('data.json');
        $file->method('getClientOriginalExtension')->willReturn('json');
        $file->method('getClientMimeType')->willReturn('application/json');
        $file->method('getSize')->willReturn(512);
        $file->method('getPathname')->willReturn(__FILE__);

        $this->storage->expects($this->once())->method('writeStream');

        $result = $this->service->upload($file);

        $this->assertSame('data.json', $result['fileName']);
        $this->assertSame('application/json', $result['mimeType']);
    }

    #[Test]
    public function uploadAcceptsAllowedExtensionAndMimeType(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getClientOriginalName')->willReturn('photo.jpg');
        $file->method('getClientOriginalExtension')->willReturn('jpg');
        $file->method('getClientMimeType')->willReturn('image/jpeg');
        $file->method('getSize')->willReturn(1024);
        $file->method('getPathname')->willReturn(__FILE__); // use dummy local file

        $this->storage
            ->expects($this->once())
            ->method('writeStream')
            ->with(
                $this->callback(
                    static fn($filename) => str_starts_with($filename, 'photo-') && str_ends_with($filename, '.jpg'),
                ),
                $this->anything(),
            );

        $result = $this->service->upload($file);

        $this->assertSame('photo.jpg', $result['fileName']);
        $this->assertSame(1024, $result['fileSize']);
        $this->assertSame('image/jpeg', $result['mimeType']);
        $this->assertStringEndsWith('.jpg', $result['filePath']);
    }

    #[Test]
    public function uploadRejectsFileExceedingMaxSize(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getClientOriginalName')->willReturn('huge_image.jpg');
        $file->method('getClientOriginalExtension')->willReturn('jpg');
        $file->method('getClientMimeType')->willReturn('image/jpeg');
        // Let's make the size exceed 10MB limit (10MB + 1 byte)
        $file->method('getSize')->willReturn(10_485_761);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le fichier dépasse la taille maximale autorisée de 10 Mo.');

        $this->service->upload($file);
    }

    #[Test]
    public function uploadUsesServerGuessedMimeType(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getClientOriginalName')->willReturn('photo.jpg');
        $file->method('getClientOriginalExtension')->willReturn('jpg');
        // Client says text/plain, but server detects image/jpeg
        $file->method('getClientMimeType')->willReturn('text/plain');
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('getSize')->willReturn(1024);
        $file->method('getPathname')->willReturn(__FILE__);

        $this->storage->expects($this->once())->method('writeStream');

        $result = $this->service->upload($file);

        $this->assertSame('image/jpeg', $result['mimeType']);
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'svg-test');
        $this->assertIsString($path);
        file_put_contents($path, $content);

        return $path;
    }

    private function svgUploadedFile(string $path): UploadedFile
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getClientOriginalName')->willReturn('icon.svg');
        $file->method('getClientOriginalExtension')->willReturn('svg');
        $file->method('getClientMimeType')->willReturn('image/svg+xml');
        $file->method('getMimeType')->willReturn('image/svg+xml');
        $file->method('getSize')->willReturn((int) filesize($path));
        $file->method('getPathname')->willReturn($path);

        return $file;
    }

    #[Test]
    public function uploadSanitizesSvgAndStripsScripts(): void
    {
        $path = $this->tempFile(
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)">'
            . '<script>fetch("https://evil.example/x")</script>'
            . '<circle cx="10" cy="10" r="5"/></svg>',
        );

        $stored = null;
        $this->storage
            ->expects($this->once())
            ->method('write')
            ->with(
                $this->callback(static fn($filename) => str_ends_with($filename, '.svg')),
                $this->callback(static function (string $content) use (&$stored) {
                    $stored = $content;

                    return true;
                }),
            );

        $result = $this->service->upload($this->svgUploadedFile($path));

        $this->assertSame('image/svg+xml', $result['mimeType']);
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onload', $stored);
        $this->assertStringContainsString('<circle', $stored);
    }

    #[Test]
    public function uploadRejectsSvgThatSanitizesToEmpty(): void
    {
        $path = $this->tempFile('');

        $this->storage->expects($this->never())->method('write');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le fichier SVG est invalide ou a été rejeté après analyse.');

        $this->service->upload($this->svgUploadedFile($path));
    }

    #[Test]
    public function uploadRejectsMalformedSvg(): void
    {
        $path = $this->tempFile('<svg><broken');

        $this->storage->expects($this->never())->method('write');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le fichier SVG est invalide ou a été rejeté après analyse.');

        $this->service->upload($this->svgUploadedFile($path));
    }
}
