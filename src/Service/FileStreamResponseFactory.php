<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ChannelExport;
use App\Entity\Message;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

class FileStreamResponseFactory
{
    /**
     * MIME types rendered as text/plain instead of being executed/served raw.
     *
     * @var list<string>
     */
    private const array TEXT_PLAIN_DOWNGRADES = [
        'text/html',
        'text/x-php',
        'application/x-php',
        'application/x-httpd-php',
        'application/javascript',
        'text/javascript',
        'text/css',
        'application/json',
        'text/xml',
        'application/xml',
    ];

    /**
     * MIME types served as octet-stream (never rendered by the browser).
     *
     * @var list<string>
     */
    private const array BINARY_DOWNGRADES = [
        'image/svg+xml',
        'application/zip',
        'application/x-tar',
        'application/gzip',
        'application/x-gzip',
        'application/x-zip-compressed',
        'application/x-rar-compressed',
    ];

    /**
     * MIME types that must always be served as attachment, never inline.
     *
     * @var list<string>
     */
    private const array UNSAFE_INLINE_TYPES = [
        'text/html',
        'text/x-php',
        'application/x-php',
        'application/x-httpd-php',
        'application/javascript',
        'text/javascript',
        'text/css',
        'application/json',
        'text/xml',
        'application/xml',
        'image/svg+xml',
    ];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * Streams a resource stream with proper closure handling and HTTP headers.
     *
     * @param resource $stream
     * @param array<string, string> $headers
     */
    public function createStreamedResponse(
        mixed $stream,
        string $contentType,
        ?string $filename = null,
        string $disposition = HeaderUtils::DISPOSITION_ATTACHMENT,
        array $headers = [],
    ): StreamedResponse {
        $response = new StreamedResponse();
        $response->setCallback(static function () use ($stream): void {
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        });

        $response->setStatusCode(Response::HTTP_OK);
        $response->headers->set('Content-Type', $contentType);

        if ($filename !== null && $filename !== '') {
            $fallback = self::getFallbackFileName($filename);
            $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
                $disposition,
                $filename,
                $fallback,
            ));
        }

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * Builds a secure streamed response for an uploaded message file attachment or preview.
     */
    public function createMessageFileResponse(
        Message $message,
        Request $request,
        FileUploadService $fileUploadService,
        string $dispositionType = HeaderUtils::DISPOSITION_ATTACHMENT,
        ?string $contentType = null,
    ): Response {
        $filePath = (string) $message->getFilePath();
        $updatedAtTimestamp = $message->getUpdatedAt()?->getTimestamp() ?? $message->getCreatedAt()->getTimestamp();
        $etag = md5($filePath . $updatedAtTimestamp);

        $response = new StreamedResponse();
        $response->setEtag($etag);
        $response->setPrivate();
        $response->setMaxAge(31_536_000);
        $response->headers->addCacheControlDirective('immutable');

        if ($response->isNotModified($request)) {
            return $response;
        }

        if (!$fileUploadService->exists($filePath)) {
            throw new NotFoundHttpException($this->translator->trans('Le fichier n\'existe pas.'));
        }

        $stream = $fileUploadService->readStream($filePath);
        $resolvedContentType = $contentType ?? self::resolveMessageContentType($message);

        $response->setCallback(static function () use ($stream): void {
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        });

        $response->setStatusCode(Response::HTTP_OK);
        $response->headers->set('Content-Type', $resolvedContentType);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $dispositionType,
            (string) $message->getFileName(),
            self::getFallbackFileName((string) $message->getFileName()),
        ));

        return $response;
    }

    /**
     * Builds a streamed response for an avatar image file.
     */
    public function createAvatarResponse(
        string $avatarPath,
        FileUploadService $fileUploadService,
    ): StreamedResponse {
        if (!$fileUploadService->exists($avatarPath)) {
            throw new NotFoundHttpException($this->translator->trans('Le fichier n\'existe pas.'));
        }

        $stream = $fileUploadService->readStream($avatarPath);
        $ext = strtolower(pathinfo($avatarPath, PATHINFO_EXTENSION));
        $mimeType = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };

        return $this->createStreamedResponse(
            $stream,
            $mimeType,
            null,
            HeaderUtils::DISPOSITION_INLINE,
            [
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'Content-Security-Policy' => 'sandbox',
            ],
        );
    }

    /**
     * Builds a streamed download response for a channel export archive.
     */
    public function createExportDownloadResponse(
        ChannelExport $export,
        FileUploadService $fileUploadService,
    ): StreamedResponse {
        if (!$fileUploadService->exists($export->getFilePath())) {
            throw new NotFoundHttpException($this->translator->trans(
                'Le fichier d\'export n\'existe pas dans le stockage.',
            ));
        }

        $contentType = str_ends_with($export->getFileName(), '.tar') ? 'application/x-tar' : 'application/zip';
        $stream = $fileUploadService->readStream($export->getFilePath());

        return $this->createStreamedResponse(
            $stream,
            $contentType,
            $export->getFileName(),
            HeaderUtils::DISPOSITION_ATTACHMENT,
        );
    }

    /**
     * Determines whether the file is unsafe for inline preview in the browser.
     */
    public static function isUnsafeForInlinePreview(Message $message): bool
    {
        $mimeType = strtolower($message->getMimeType() ?? '');

        return in_array($mimeType, self::UNSAFE_INLINE_TYPES, true);
    }

    /**
     * Determines the safe preview Content-Type for a message file.
     */
    public static function getPreviewContentType(Message $message): string
    {
        $mimeType = $message->getMimeType();
        if ($mimeType === null || $mimeType === '') {
            return 'application/octet-stream';
        }

        $lower = strtolower($mimeType);
        if (in_array($lower, self::TEXT_PLAIN_DOWNGRADES, true)) {
            return 'text/plain';
        }

        if (in_array($lower, self::BINARY_DOWNGRADES, true)) {
            return 'application/octet-stream';
        }

        return $mimeType;
    }

    /**
     * Computes a safe ASCII fallback file name for Content-Disposition.
     */
    public static function getFallbackFileName(string $filename): string
    {
        $fallback = '';
        if (function_exists('transliterator_transliterate')) {
            $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII', $filename);
            if ($transliterated !== false) {
                $fallback = $transliterated;
            }
        }

        if ($fallback === '' && function_exists('iconv')) {
            $iconvFallback = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename);
            if ($iconvFallback !== false) {
                $fallback = $iconvFallback;
            }
        }

        $fallback = preg_replace('/[^\x20-\x7E]/', '', $fallback === '' ? $filename : $fallback);
        $fallback = trim((string) $fallback);

        $nameWithoutExt = pathinfo($fallback, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        if ($nameWithoutExt === '' || preg_match('/^[.\s]*$/', $nameWithoutExt)) {
            $fallback = $ext !== '' ? 'file.' . $ext : 'file';
        }

        return $fallback;
    }

    private static function resolveMessageContentType(Message $message): string
    {
        $mimeType = $message->getMimeType();
        if ($mimeType !== null && $mimeType !== '') {
            return $mimeType;
        }

        return 'application/octet-stream';
    }
}
