<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\File\UploadedFileMetadata;
use enshrined\svgSanitize\Sanitizer;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Handles file upload and deletion via Flysystem.
 */
class FileUploadService
{
    private const ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'svg',
        'pdf',
        'txt',
        'md',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'ppt',
        'pptx',
        'json',
        'html',
        'mp3',
        'ogg',
        'wav',
        'mp4',
        'webm',
        'mov',
        'zip',
        'tar',
        'gz',
        'rar',
        'php',
        'js',
        'ts',
        'tsx',
        'py',
        'go',
        'rs',
        'sh',
        'sql',
        'c',
        'cpp',
        'cs',
        'java',
        'css',
        'yaml',
        'yml',
        'xml',
    ];

    private const ALLOWED_MIME_TYPES = [
        // Images
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        // Documents
        'application/pdf',
        'text/plain',
        'text/markdown',
        'application/json',
        'text/html',
        'text/x-php',
        'application/x-php',
        'application/x-httpd-php',
        'application/javascript',
        'text/javascript',
        'text/css',
        'text/yaml',
        'application/x-yaml',
        'application/xml',
        'text/xml',
        'text/x-python',
        'application/x-python',
        'text/x-go',
        'text/rust',
        'text/x-rust',
        'application/x-sh',
        'text/x-shellscript',
        'text/x-sh',
        'application/sql',
        'text/x-sql',
        'text/x-c',
        'text/x-csrc',
        'text/x-c++',
        'text/x-c++src',
        'text/x-c++hdr',
        'text/x-csharp',
        'text/x-java-source',
        'text/x-java',
        'text/x-yaml',
        'video/mp2t',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        // Audio
        'audio/mpeg',
        'audio/ogg',
        'audio/wav',
        'audio/webm',
        // Video
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/quicktime',
        // Archives
        'application/zip',
        'application/x-tar',
        'application/gzip',
        'application/x-gzip',
        'application/x-zip-compressed',
        'application/x-rar-compressed',
    ];

    private const MAX_FILE_SIZE = 10_485_760; // 10MB

    public function __construct(
        #[Target('defaultStorage')]
        private readonly FilesystemOperator $defaultStorage,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * Uploads an UploadedFile to the default storage and returns file metadata.
     *
     * @throws \InvalidArgumentException if the file type or extension is not allowed
     */
    public function upload(UploadedFile $file): UploadedFileMetadata
    {
        $extension = $this->extractExtension($file);
        $this->validateFile($file, $extension);
        $mimeType = $this->resolveMimeType($file, $extension);

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

    private function extractExtension(UploadedFile $file): string
    {
        $origExt = $file->getClientOriginalExtension();
        if ($origExt !== null && $origExt !== '') {
            return strtolower($origExt);
        }

        $guessedExt = $file->guessExtension();
        if ($guessedExt !== null && $guessedExt !== '') {
            return strtolower($guessedExt);
        }

        return 'bin';
    }

    private function validateFile(UploadedFile $file, string $extension): void
    {
        if (!$file->isValid()) {
            $this->logger->warning(sprintf(
                'File upload failed validation: file "%s" is invalid or exceeds PHP post size limit.',
                $file->getClientOriginalName(),
            ));
            throw new \InvalidArgumentException($this->translator->trans(
                'Le fichier est invalide ou dépasse la taille autorisée par le serveur.',
            ));
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            $this->logger->warning(sprintf(
                'File upload failed validation: file "%s" size %d exceeds limit %d.',
                $file->getClientOriginalName(),
                $file->getSize(),
                self::MAX_FILE_SIZE,
            ));
            throw new \InvalidArgumentException($this->translator->trans('Le fichier dépasse la taille maximale autorisée de %maxSize% Mo.', [
                '%maxSize%' => (self::MAX_FILE_SIZE / 1024) / 1024,
            ]));
        }

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $this->logger->warning(sprintf(
                'File upload failed validation: extension ".%s" for file "%s" is not allowed.',
                $extension,
                $file->getClientOriginalName(),
            ));
            throw new \InvalidArgumentException($this->translator->trans('L\'extension de fichier ".%extension%" n\'est pas autorisée.', [
                '%extension%' => $extension,
            ]));
        }
    }

    private function resolveMimeType(UploadedFile $file, string $extension): string
    {
        $mimeType = $file->getMimeType() ?? $file->getClientMimeType();

        // Content-based MIME detection can misidentify text files (e.g.,
        // markdown with code blocks detected as JavaScript). When the
        // extension is explicitly allowed, we verify if the detected MIME type
        // is compatible with the extension. If not, we fall back to the standard
        // MIME type mapped to that extension.
        if (in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $mimeFromExt = new MimeTypes()->getMimeTypes($extension);
            if (!in_array($mimeType, $mimeFromExt, true)) {
                foreach ($mimeFromExt as $candidate) {
                    if (!in_array($candidate, self::ALLOWED_MIME_TYPES, true)) {
                        continue;
                    }

                    $mimeType = $candidate;
                    break;
                }
            }
        }

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            $this->logger->warning(sprintf(
                'File upload failed validation: MIME type "%s" for file "%s" is not allowed.',
                $mimeType,
                $file->getClientOriginalName(),
            ));
            throw new \InvalidArgumentException($this->translator->trans('Le type MIME "%mimeType%" n\'est pas autorisé.', [
                '%mimeType%' => $mimeType,
            ]));
        }

        return (string) $mimeType;
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
