<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\FileUploadPolicy;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class FileUploadPolicyTest extends TestCase
{
    private FileUploadPolicy $policy;

    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn($id, $parameters = []) => strtr($id, $parameters));
        $logger = $this->createMock(LoggerInterface::class);

        $this->policy = new FileUploadPolicy($translator, $logger);
    }

    #[Test]
    public function isExtensionAllowedRecognizesStandardExtensions(): void
    {
        static::assertTrue($this->policy->isExtensionAllowed('jpg'));
        static::assertTrue($this->policy->isExtensionAllowed('PNG'));
        static::assertTrue($this->policy->isExtensionAllowed('pdf'));
        static::assertTrue($this->policy->isExtensionAllowed('json'));
        static::assertFalse($this->policy->isExtensionAllowed('exe'));
        static::assertFalse($this->policy->isExtensionAllowed('bat'));
    }

    #[Test]
    public function isMimeTypeAllowedRecognizesStandardMimes(): void
    {
        static::assertTrue($this->policy->isMimeTypeAllowed('image/png'));
        static::assertTrue($this->policy->isMimeTypeAllowed('application/pdf'));
        static::assertTrue($this->policy->isMimeTypeAllowed('text/plain'));
        static::assertFalse($this->policy->isMimeTypeAllowed('application/x-msdownload'));
    }

    #[Test]
    public function validateRejectsInvalidOrLargeFile(): void
    {
        $invalidFile = $this->createMock(UploadedFile::class);
        $invalidFile->method('isValid')->willReturn(false);
        $invalidFile->method('getClientOriginalName')->willReturn('bad.txt');

        $this->expectException(\InvalidArgumentException::class);
        $this->policy->validate($invalidFile, 'txt');
    }

    #[Test]
    public function resolveMimeTypeCorrectsMarkdownMime(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('text/javascript');
        $file->method('getClientMimeType')->willReturn('text/plain');

        $resolved = $this->policy->resolveMimeType($file, 'md');
        static::assertSame('text/markdown', $resolved);
    }
}
