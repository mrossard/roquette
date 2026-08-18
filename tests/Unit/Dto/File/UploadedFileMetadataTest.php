<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\File;

use App\Dto\File\UploadedFileMetadata;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class UploadedFileMetadataTest extends TestCase
{
    #[Test]
    public function propertiesAndArrayAccessWorkCorrectly(): void
    {
        $meta = new UploadedFileMetadata(
            fileName: 'doc.pdf',
            filePath: 'uploads/doc-123.pdf',
            fileSize: 1024,
            mimeType: 'application/pdf',
        );

        $this->assertSame('doc.pdf', $meta->fileName);
        $this->assertSame('uploads/doc-123.pdf', $meta->filePath);
        $this->assertSame(1024, $meta->fileSize);
        $this->assertSame('application/pdf', $meta->mimeType);

        // ArrayAccess
        $this->assertTrue($meta->offsetExists('fileName'));
        $this->assertTrue($meta->offsetExists('filePath'));
        $this->assertSame('doc.pdf', $meta['fileName']);
        $this->assertSame('uploads/doc-123.pdf', $meta['filePath']);
        $this->assertSame(1024, $meta['fileSize']);
        $this->assertSame('application/pdf', $meta['mimeType']);
        $this->assertNull($meta['nonExistent']);

        // toArray
        $this->assertSame(
            [
                'fileName' => 'doc.pdf',
                'filePath' => 'uploads/doc-123.pdf',
                'fileSize' => 1024,
                'mimeType' => 'application/pdf',
            ],
            $meta->toArray(),
        );
    }

    #[Test]
    public function offsetSetThrowsBadMethodCallException(): void
    {
        $meta = new UploadedFileMetadata('doc.pdf', 'path', 10, 'text/plain');
        $this->expectException(\BadMethodCallException::class);
        $meta['fileName'] = 'other.pdf';
    }
}
