<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\File\UploadedFileMetadata;
use App\Service\File\FileUploadPolicy;
use enshrined\svgSanitize\Sanitizer;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Handles file upload and deletion via Flysystem.
 */
class FileUploadService
{
    private readonly FileUploadPolicy $policy;

    public function __construct(
        #[Target('defaultStorage')]
        private readonly FilesystemOperator $defaultStorage,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        ?FileUploadPolicy $policy = null,
    ) {
        $this->policy = $policy ?? new FileUploadPolicy($this->translator, $this->logger);
    }

    /**
     * Uploads an UploadedFile to the default storage and returns file metadata.
     *
     * @throws \InvalidArgumentException if the file type or extension is not allowed
     */
    public function upload(UploadedFile $file): UploadedFileMetadata
    {
        $extension = $this->policy->extractExtension($file);
        $this->policy->validate($file, $extension);
        $mimeType = $this->policy->resolveMimeType($file, $extension);

        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $originalFilename);
        $newFilename = $safeFilename . '-' . uniqid() . '.' . $extension;

        $fileSize = (int) $file->getSize();
        $fileName = $file->getClientOriginalName();

        $this->storeFile($file, $newFilename, $extension);

        $this->logger->info(sprintf(
            'File upload successful: "%s" saved as "%s" (%d bytes, MIME: %s).',
            $fileName,
            $newFilename,
            $fileSize,
            $mimeType,
        ));

        return new UploadedFileMetadata(
            fileName: $fileName,
            filePath: $newFilename,
            fileSize: $fileSize,
            mimeType: $mimeType,
        );
    }

    private function storeFile(UploadedFile $file, string $newFilename, string $extension): void
    {
        $stream = \fopen($file->getPathname(), 'r');

        if ($extension === 'svg') {
            $cleanSvg = $this->sanitizeSvg($stream, $file->getClientOriginalName());
            $this->defaultStorage->write($newFilename, $cleanSvg);
            return;
        }

        $this->defaultStorage->writeStream($newFilename, $stream);
        if (is_resource($stream)) {
            \fclose($stream);
        }
    }

    private function sanitizeSvg(mixed $stream, string $fileName): string
    {
        $dirtySvg = \stream_get_contents($stream);
        if (is_resource($stream)) {
            \fclose($stream);
        }

        $cleanSvg = new Sanitizer()->sanitize((string) $dirtySvg);

        if ($cleanSvg === false || $cleanSvg === null || $cleanSvg === '') {
            $this->logger->warning(sprintf(
                'File upload rejected: SVG file "%s" could not be sanitized.',
                $fileName,
            ));
            throw new \InvalidArgumentException($this->translator->trans(
                'Le fichier SVG est invalide ou a été rejeté après analyse.',
            ));
        }

        return $cleanSvg;
    }

    /**
     * Deletes a stored file if it exists.
     */
    public function delete(string $filePath): void
    {
        if ($this->defaultStorage->has($filePath)) {
            $this->defaultStorage->delete($filePath);
            $this->logger->info(sprintf('Stored file deleted: "%s".', $filePath));
        }
    }

    /**
     * Returns whether a stored file exists.
     */
    public function exists(string $filePath): bool
    {
        return $this->defaultStorage->has($filePath);
    }

    /**
     * Returns a readable stream for a stored file.
     *
     * @return resource
     */
    public function readStream(string $filePath): mixed
    {
        return $this->defaultStorage->readStream($filePath);
    }

    /**
     * Writes raw contents to a stored file path.
     */
    public function write(string $filePath, string $contents): void
    {
        $this->defaultStorage->write($filePath, $contents);
    }

    /**
     * Writes a stream to a stored file path.
     *
     * @param resource $stream
     */
    public function writeStream(string $filePath, mixed $stream): void
    {
        $this->defaultStorage->writeStream($filePath, $stream);
    }

    /**
     * Uploads an UploadedFile and populates metadata on a Message entity.
     *
     * @throws \InvalidArgumentException if the file is invalid or not allowed
     */
    public function uploadAndAttachToMessage(UploadedFile $file, \App\Entity\Message $message): void
    {
        $meta = $this->upload($file);
        $message->setFileName($meta->fileName);
        $message->setFilePath($meta->filePath);
        $message->setFileSize($meta->fileSize);
        $message->setMimeType($meta->mimeType);
    }
}
