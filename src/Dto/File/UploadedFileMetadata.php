<?php

declare(strict_types=1);

namespace App\Dto\File;

/**
 * Metadata of an uploaded file.
 * Implements \ArrayAccess for backwards compatibility with array-access patterns.
 *
 * @implements \ArrayAccess<string, mixed>
 */
final readonly class UploadedFileMetadata implements \ArrayAccess
{
    public function __construct(
        public string $fileName,
        public string $filePath,
        public int $fileSize,
        public string $mimeType,
    ) {}

    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, ['fileName', 'filePath', 'fileSize', 'mimeType'], true);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'fileName' => $this->fileName,
            'filePath' => $this->filePath,
            'fileSize' => $this->fileSize,
            'mimeType' => $this->mimeType,
            default => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \BadMethodCallException('UploadedFileMetadata is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \BadMethodCallException('UploadedFileMetadata is immutable.');
    }

    /**
     * @return array{fileName: string, filePath: string, fileSize: int, mimeType: string}
     */
    public function toArray(): array
    {
        return [
            'fileName' => $this->fileName,
            'filePath' => $this->filePath,
            'fileSize' => $this->fileSize,
            'mimeType' => $this->mimeType,
        ];
    }
}
