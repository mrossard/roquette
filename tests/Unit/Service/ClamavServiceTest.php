<?php

declare(strict_types=1);

namespace App\Service;

use App\Tests\Unit\Service\ClamavMockStream;
use App\Tests\Unit\Service\ClamavServiceTestState;

function fsockopen(string $hostname, int $port, &$errno, &$errstr, float $timeout)
{
    if (ClamavServiceTestState::$shouldFailFsockopen) {
        $errno = 111;
        $errstr = 'Connection refused';
        return false;
    }

    if (!ClamavServiceTestState::$streamWrapperRegistered) {
        stream_wrapper_register('clamavmock', ClamavMockStream::class);
        ClamavServiceTestState::$streamWrapperRegistered = true;
    }

    return fopen('clamavmock://stream', 'r+');
}

function fopen(string $filename, string $mode)
{
    if (str_starts_with($filename, 'clamavmock://')) {
        return \fopen($filename, $mode);
    }

    if (ClamavServiceTestState::$shouldFailFopen) {
        return false;
    }

    // For files being scanned, return a temporary resource
    $file = tmpfile();
    fwrite($file, 'dummy file content');
    fseek($file, 0);
    return $file;
}

namespace App\Tests\Unit\Service;

use App\Service\ClamavService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ClamavServiceTest extends TestCase
{
    private LoggerInterface $logger;
    private ClamavService $service;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new ClamavService('localhost', 3310, $this->logger);

        // Reset state
        ClamavServiceTestState::$shouldFailFsockopen = false;
        ClamavServiceTestState::$shouldFailFopen = false;
        ClamavServiceTestState::$response = '';
    }

    #[Test]
    public function scanFileThrowsExceptionIfSocketFails(): void
    {
        ClamavServiceTestState::$shouldFailFsockopen = true;

        $file = $this->createMock(UploadedFile::class);
        $file->method('getPathname')->willReturn('/tmp/test-file');

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Failed to connect to ClamAV daemon'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Le service d\'analyse antivirus est temporairement indisponible.');

        $this->service->scanFile($file);
    }

    #[Test]
    public function scanFileThrowsExceptionIfFileCannotBeOpened(): void
    {
        ClamavServiceTestState::$shouldFailFopen = true;

        $file = $this->createMock(UploadedFile::class);
        $file->method('getPathname')->willReturn('/tmp/test-file');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Impossible d\'ouvrir le fichier pour l\'analyse antivirus.');

        $this->service->scanFile($file);
    }

    #[Test]
    public function scanFileReturnsTrueIfClean(): void
    {
        ClamavServiceTestState::$response = "stream: OK\n";

        $file = $this->createMock(UploadedFile::class);
        $file->method('getPathname')->willReturn('/tmp/test-file');

        $result = $this->service->scanFile($file);
        $this->assertTrue($result);
    }

    #[Test]
    public function scanFileReturnsFalseIfVirusDetected(): void
    {
        ClamavServiceTestState::$response = "stream: Eicar-Signature FOUND\n";

        $file = $this->createMock(UploadedFile::class);
        $file->method('getPathname')->willReturn('/tmp/test-file');
        $file->method('getClientOriginalName')->willReturn('eicar.com');

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Virus detected in uploaded file "eicar.com"'));

        $result = $this->service->scanFile($file);
        $this->assertFalse($result);
    }

    #[Test]
    public function scanFileThrowsExceptionOnUnexpectedResponse(): void
    {
        ClamavServiceTestState::$response = "some unexpected error\n";

        $file = $this->createMock(UploadedFile::class);
        $file->method('getPathname')->willReturn('/tmp/test-file');
        $file->method('getClientOriginalName')->willReturn('test.txt');

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with($this->stringContains('ClamAV returned an unexpected response'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Une erreur est survenue lors de l\'analyse antivirus du fichier.');

        $this->service->scanFile($file);
    }
}
