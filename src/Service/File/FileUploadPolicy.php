<?php

declare(strict_types=1);

namespace App\Service\File;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Encapsulates validation and MIME resolution policy for file uploads.
 */
class FileUploadPolicy
{
    public const int MAX_FILE_SIZE = 10_485_760; // 10MB

    public const array ALLOWED_EXTENSIONS = [
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

    public const array ALLOWED_MIME_TYPES = [
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

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function getMaxFileSize(): int
    {
        return self::MAX_FILE_SIZE;
    }

    public function isExtensionAllowed(string $extension): bool
    {
        return in_array(strtolower($extension), self::ALLOWED_EXTENSIONS, true);
    }

    public function isMimeTypeAllowed(string $mimeType): bool
    {
        return in_array($mimeType, self::ALLOWED_MIME_TYPES, true);
    }

    public function extractExtension(UploadedFile $file): string
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

    public function validate(UploadedFile $file, string $extension): void
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

        if (!$this->isExtensionAllowed($extension)) {
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

    public function resolveMimeType(UploadedFile $file, string $extension): string
    {
        $mimeType = $file->getMimeType() ?? $file->getClientMimeType();

        // Content-based MIME detection can misidentify text files (e.g.,
        // markdown with code blocks detected as JavaScript). When the
        // extension is explicitly allowed, we verify if the detected MIME type
        // is compatible with the extension. If not, we fall back to the standard
        // MIME type mapped to that extension.
        if ($this->isExtensionAllowed($extension)) {
            $mimeFromExt = new MimeTypes()->getMimeTypes($extension);
            if (!in_array($mimeType, $mimeFromExt, true)) {
                foreach ($mimeFromExt as $candidate) {
                    if (!$this->isMimeTypeAllowed($candidate)) {
                        continue;
                    }

                    $mimeType = $candidate;
                    break;
                }
            }
        }

        if (!$this->isMimeTypeAllowed((string) $mimeType)) {
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
}
