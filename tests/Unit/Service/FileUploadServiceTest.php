<?php

namespace App\Tests\Unit\Service;

use App\Service\ClamavService;
use App\Service\FileUploadService;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileUploadServiceTest extends TestCase
{
    private FilesystemOperator&MockObject $storage;
    private ClamavService&MockObject $clamavService;
    private FileUploadService $service;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(FilesystemOperator::class);
        $this->clamavService = $this->createMock(ClamavService::class);
        $this->clamavService->method('scanFile')->willReturn(true);
        $this->service = new FileUploadService($this->storage, $this->clamavService);
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
    public function uploadRejectsUnallowedMimeType(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getClientOriginalName')->willReturn('test.png');
        $file->method('getClientOriginalExtension')->willReturn('png');
        // Let's pass an invalid mime type for a PNG extension
        $file->method('getClientMimeType')->willReturn('text/html');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le type MIME "text/html" n\'est pas autorisé.');

        $this->service->upload($file);
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

        $this->storage->expects($this->once())
            ->method('writeStream')
            ->with($this->callback(static function ($filename) {
                return str_starts_with($filename, 'photo-') && str_ends_with($filename, '.jpg');
            }), $this->anything());

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
    public function uploadRejectsInfectedFile(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getClientOriginalName')->willReturn('infected.jpg');
        $file->method('getClientOriginalExtension')->willReturn('jpg');
        $file->method('getClientMimeType')->willReturn('image/jpeg');
        $file->method('getSize')->willReturn(1024);

        // Make ClamAV find a virus
        $this->clamavService = $this->createMock(ClamavService::class);
        $this->clamavService->method('scanFile')->willReturn(false);
        $this->service = new FileUploadService($this->storage, $this->clamavService);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le fichier contient un virus ou un logiciel malveillant.');

        $this->storage->expects($this->never())->method('writeStream');

        $this->service->upload($file);
    }
}
