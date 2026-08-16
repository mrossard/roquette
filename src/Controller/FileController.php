<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Entity\Message;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Service\FileUploadService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class FileController extends AbstractController
{
    use ChannelAccessTrait;

    /**
     * MIME types rendered as text/plain instead of being executed/served raw.
     *
     * @var list<string>
     */
    private const TEXT_PLAIN_DOWNGRADES = [
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
    private const BINARY_DOWNGRADES = [
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
    private const UNSAFE_INLINE_TYPES = [
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
        private TranslatorInterface $translator,
    ) {}

    #[Route('/messages/{id}/download', name: 'app_file_download', methods: ['GET'])]
    public function downloadFile(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        FileUploadService $fileUploadService,
    ): Response {
        $message = $this->findAndAuthorizeFileMessage($id, $messageRepository);
        $contentType = $message->getMimeType() !== null && $message->getMimeType() !== ''
            ? $message->getMimeType()
            : 'application/octet-stream';

        return $this->serveStreamedFile($message, $request, $fileUploadService, HeaderUtils::DISPOSITION_ATTACHMENT, $contentType);
    }

    #[Route('/messages/{id}/preview', name: 'app_file_preview', methods: ['GET'])]
    public function previewFile(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        FileUploadService $fileUploadService,
    ): Response {
        $message = $this->findAndAuthorizeFileMessage($id, $messageRepository);
        $disposition = self::isUnsafeForInlinePreview($message)
            ? HeaderUtils::DISPOSITION_ATTACHMENT
            : HeaderUtils::DISPOSITION_INLINE;

        return $this->serveStreamedFile($message, $request, $fileUploadService, $disposition, self::previewContentType($message));
    }

    private function findAndAuthorizeFileMessage(int $id, MessageRepository $messageRepository): Message
    {
        $message = $messageRepository->find($id);
        if (!$message || !$message->getFilePath()) {
            throw $this->createNotFoundException($this->translator->trans('Fichier non trouvé.'));
        }

        $this->checkVirusScanStatus($message);
        $this->authorizeMessageAccess($message);

        return $message;
    }

    private function serveStreamedFile(
        Message $message,
        Request $request,
        FileUploadService $fileUploadService,
        string $dispositionType,
        string $contentType,
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
            throw $this->createNotFoundException($this->translator->trans('Le fichier n\'existe pas.'));
        }

        $stream = $fileUploadService->readStream($filePath);

        $response->setCallback(static function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        });

        $response->setStatusCode(200);
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $dispositionType,
            $message->getFileName(),
            $this->getFallbackFileName($message->getFileName()),
        ));

        return $response;
    }

    #[Route('/messages/{id}/text-preview', name: 'app_file_text_preview', methods: ['GET'])]
    public function textPreview(
        int $id,
        MessageRepository $messageRepository,
        FileUploadService $fileUploadService,
        Request $request,
    ): Response {
        $message = $messageRepository->find($id);
        if (!$message || !$message->getFilePath()) {
            throw $this->createNotFoundException($this->translator->trans('Fichier non trouvé.'));
        }

        $this->checkVirusScanStatus($message);

        $this->authorizeMessageAccess($message);

        if (!$fileUploadService->exists($message->getFilePath())) {
            throw $this->createNotFoundException($this->translator->trans('Le fichier n\'existe pas.'));
        }

        $stream = $fileUploadService->readStream($message->getFilePath());
        $text = stream_get_contents($stream, 10_000);

        $isTruncated = false;
        // If there is still at least one character to read, the content was truncated
        if (fgetc($stream) !== false) {
            $isTruncated = true;
        }

        if (is_resource($stream)) {
            fclose($stream);
        }

        if ($isTruncated) {
            $text .=
                "\n\n... ["
                . $this->translator->trans('Contenu tronqué, téléchargez le fichier pour le lire en entier')
                . ']';
        }

        $fileExt = pathinfo($message->getFileName(), PATHINFO_EXTENSION);
        $raw = $request->query->getBoolean('raw');

        return $this->render('dashboard/_text_preview.html.twig', [
            'message_id' => $message->getId(),
            'text' => $text,
            'fileExt' => $fileExt,
            'raw' => $raw,
        ]);
    }

    #[Route('/messages/{id}/text-preview/hide', name: 'app_file_text_preview_hide', methods: ['GET'])]
    public function textPreviewHide(int $id, MessageRepository $messageRepository): Response
    {
        $message = $messageRepository->find($id);
        if (!$message) {
            throw $this->createNotFoundException($this->translator->trans('Message non trouvé.'));
        }

        $this->authorizeMessageAccess($message);

        $fileExt = pathinfo($message->getFileName(), PATHINFO_EXTENSION);

        return $this->render('dashboard/_text_preview_button.html.twig', [
            'message_id' => $message->getId(),
            'fileExt' => $fileExt,
        ]);
    }

    #[Route('/messages/{id}/lightbox', name: 'app_lightbox', methods: ['GET'])]
    public function lightbox(int $id, MessageRepository $messageRepository): Response
    {
        $message = $messageRepository->find($id);
        if (!$message || !$message->getFilePath()) {
            throw $this->createNotFoundException($this->translator->trans('Fichier non trouvé.'));
        }

        $this->checkVirusScanStatus($message);

        $this->authorizeMessageAccess($message);

        return $this->render('modals/_lightbox_content.html.twig', [
            'message_id' => $message->getId(),
            'fileName' => $message->getFileName(),
            'previewUrl' => $this->generateUrl('app_file_preview', ['id' => $message->getId()]),
            'downloadUrl' => $this->generateUrl('app_file_download', ['id' => $message->getId()]),
        ]);
    }

    private function getFallbackFileName(string $filename): string
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
        $fallback = trim($fallback);

        if ($fallback === '' || preg_match('/^[.\s]*$/', $fallback)) {
            $fallback = 'file';
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if ($ext !== '') {
                $fallback .= '.' . $ext;
            }
        }

        return $fallback;
    }

    private function checkVirusScanStatus(Message $message): void
    {
        if ($message->getVirusScanStatus() !== null && $message->getVirusScanStatus() !== 'clean') {
            throw $this->createAccessDeniedException(
                $message->getVirusScanStatus() === 'pending'
                    ? $this->translator->trans('L\'analyse antivirus de ce fichier est en cours.')
                    : $this->translator->trans('L\'accès à ce fichier a été bloqué par l\'antivirus.'),
            );
        }
    }

    #[Route('/channels/{slug}/files-list', name: 'app_channel_files_list', methods: ['GET'])]
    public function channelFilesList(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        MessageRepository $messageRepository,
    ): Response {
        try {
            $channel = $this->findAndAuthorizeChannel($slug, $channelRepository);
        } catch (HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        $beforeId = $request->query->getInt('beforeId');
        $beforeId = $beforeId > 0 ? $beforeId : null;

        $messagesWithFiles = $messageRepository->findFilesByChannel($channel, 50, $beforeId);
        $hasMore = count($messagesWithFiles) === 50;
        $nextBeforeId = $hasMore ? $messagesWithFiles[array_key_last($messagesWithFiles)]->getId() : null;

        if ($beforeId !== null) {
            return $this->render('dashboard/_more_files.html.twig', [
                'activeChannel' => $channel,
                'messagesWithFiles' => $messagesWithFiles,
                'hasMore' => $hasMore,
                'nextBeforeId' => $nextBeforeId,
            ]);
        }

        return $this->render('dashboard/_files_list.html.twig', [
            'activeChannel' => $channel,
            'messagesWithFiles' => $messagesWithFiles,
            'hasMore' => $hasMore,
            'nextBeforeId' => $nextBeforeId,
        ]);
    }

    /**
     * Returns the Content-Type to use for inline preview.
     * HTML files are served as text/plain and SVG as octet-stream to prevent
     * browser rendering (defense in depth against stored XSS).
     */
    private static function previewContentType(Message $message): string
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
     * Returns whether the file must never be rendered inline in the app origin
     * (defense in depth against stored XSS via uploaded scripts/markup).
     */
    private static function isUnsafeForInlinePreview(Message $message): bool
    {
        $mimeType = strtolower($message->getMimeType() ?? '');

        return in_array($mimeType, self::UNSAFE_INLINE_TYPES, true);
    }
}
