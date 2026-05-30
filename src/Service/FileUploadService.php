<?php

namespace App\Service;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Handles file upload and deletion via Flysystem.
 *
 * Extracted from DashboardController to eliminate copy-pasted upload logic
 * across publish(), postReply() and deleteMessage() actions.
 */
class FileUploadService
{
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
        'pdf', 'txt', 'md', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'mp3', 'ogg', 'wav', 'mp4', 'webm', 'mov',
        'zip', 'tar', 'gz', 'rar'
    ];

    private const ALLOWED_MIME_TYPES = [
        // Images
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
        // Documents
        'application/pdf', 'text/plain', 'text/markdown',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        // Audio
        'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/webm',
        // Video
        'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime',
        // Archives
        'application/zip', 'application/x-tar', 'application/gzip', 'application/x-gzip', 'application/x-zip-compressed', 'application/x-rar-compressed'
    ];

    private const MAX_FILE_SIZE = 10_485_760; // 10MB

    public function __construct(
        private FilesystemOperator $defaultStorage,
        private ClamavService $clamavService
    ) {}

    /**
     * Uploads an UploadedFile to the default storage and returns file metadata.
     *
     * @return array{fileName: string, filePath: string, fileSize: int, mimeType: string}
     * @throws \InvalidArgumentException if the file type or extension is not allowed
     */
    public function upload(UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Le fichier est invalide ou dépasse la taille autorisée par le serveur.');
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $mimeType   = $file->getClientMimeType();

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException(sprintf('Le fichier dépasse la taille maximale autorisée de %d Mo.', self::MAX_FILE_SIZE / 1024 / 1024));
        }

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException(sprintf('L\'extension de fichier ".%s" n\'est pas autorisée.', $extension));
        }

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Le type MIME "%s" n\'est pas autorisé.', $mimeType));
        }

        if (!$this->clamavService->scanFile($file)) {
            throw new \InvalidArgumentException('Le fichier contient un virus ou un logiciel malveillant.');
        }

        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $originalFilename);
        $newFilename = $safeFilename . '-' . uniqid() . '.' . $extension;

        $fileSize   = $file->getSize();
        $fileName   = $file->getClientOriginalName();


        $stream = fopen($file->getPathname(), 'r');
        $this->defaultStorage->writeStream($newFilename, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return [
            'fileName' => $fileName,
            'filePath' => $newFilename,
            'fileSize' => $fileSize,
            'mimeType' => $mimeType,
        ];
    }

    /**
     * Deletes a stored file if it exists.
     */
    public function delete(string $filePath): void
    {
        if ($this->defaultStorage->has($filePath)) {
            $this->defaultStorage->delete($filePath);
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
}
