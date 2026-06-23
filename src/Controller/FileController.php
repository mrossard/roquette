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

    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    #[Route('/messages/{id}/download', name: 'app_file_download', methods: ['GET'])]
    public function downloadFile(
        int $id,
        MessageRepository $messageRepository,
        FileUploadService $fileUploadService,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $message = $messageRepository->find($id);
        if (!$message || !$message->getFilePath()) {
            throw $this->createNotFoundException($this->translator->trans('Fichier non trouvé.'));
        }

        $this->checkVirusScanStatus($message);

        $channel = $message->getChannel();
        if (($channel->isPrivate() || $channel->isDm()) && !$channel->getMembers()->contains($currentUser)) {
            throw $this->createAccessDeniedException($this->translator->trans('Non autorisé à accéder à ce fichier.'));
        }

        if (!$fileUploadService->exists($message->getFilePath())) {
            throw $this->createNotFoundException($this->translator->trans('Le fichier n\'existe pas.'));
        }

        $stream = $fileUploadService->readStream($message->getFilePath());

        return new StreamedResponse(
            static function () use ($stream) {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' =>
                    $message->getMimeType() !== null && $message->getMimeType() !== ''
                        ? $message->getMimeType()
                        : 'application/octet-stream',
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT,
                    $message->getFileName(),
                    $this->getFallbackFileName($message->getFileName()),
                ),
            ],
        );
    }

    #[Route('/messages/{id}/preview', name: 'app_file_preview', methods: ['GET'])]
    public function previewFile(
        int $id,
        MessageRepository $messageRepository,
        FileUploadService $fileUploadService,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $message = $messageRepository->find($id);
        if (!$message || !$message->getFilePath()) {
            throw $this->createNotFoundException($this->translator->trans('Fichier non trouvé.'));
        }

        $this->checkVirusScanStatus($message);

        $channel = $message->getChannel();
        if (($channel->isPrivate() || $channel->isDm()) && !$channel->getMembers()->contains($currentUser)) {
            throw $this->createAccessDeniedException($this->translator->trans('Non autorisé à accéder à ce fichier.'));
        }

        if (!$fileUploadService->exists($message->getFilePath())) {
            throw $this->createNotFoundException($this->translator->trans('Le fichier n\'existe pas.'));
        }

        $stream = $fileUploadService->readStream($message->getFilePath());

        return new StreamedResponse(
            static function () use ($stream) {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => self::previewContentType($message),
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_INLINE,
                    $message->getFileName(),
                    $this->getFallbackFileName($message->getFileName()),
                ),
            ],
        );
    }

    #[Route('/messages/{id}/text-preview', name: 'app_file_text_preview', methods: ['GET'])]
    public function textPreview(
        int $id,
        MessageRepository $messageRepository,
        FileUploadService $fileUploadService,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $message = $messageRepository->find($id);
        if (!$message || !$message->getFilePath()) {
            throw $this->createNotFoundException($this->translator->trans('Fichier non trouvé.'));
        }

        $this->checkVirusScanStatus($message);

        $channel = $message->getChannel();
        if (($channel->isPrivate() || $channel->isDm()) && !$channel->getMembers()->contains($currentUser)) {
            throw $this->createAccessDeniedException($this->translator->trans('Non autorisé à accéder à ce fichier.'));
        }

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

        return $this->render('dashboard/_text_preview.html.twig', [
            'message_id' => $message->getId(),
            'text' => $text,
            'fileExt' => $fileExt,
        ]);
    }

    #[Route('/messages/{id}/text-preview/hide', name: 'app_file_text_preview_hide', methods: ['GET'])]
    public function textPreviewHide(int $id, MessageRepository $messageRepository): Response
    {
        $message = $messageRepository->find($id);
        if (!$message) {
            throw $this->createNotFoundException($this->translator->trans('Message non trouvé.'));
        }

        return $this->render('dashboard/_text_preview_button.html.twig', [
            'message_id' => $message->getId(),
        ]);
    }

    #[Route('/messages/{id}/lightbox', name: 'app_lightbox', methods: ['GET'])]
    public function lightbox(int $id, MessageRepository $messageRepository): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $message = $messageRepository->find($id);
        if (!$message || !$message->getFilePath()) {
            throw $this->createNotFoundException($this->translator->trans('Fichier non trouvé.'));
        }

        $this->checkVirusScanStatus($message);

        $channel = $message->getChannel();
        if (($channel->isPrivate() || $channel->isDm()) && !$channel->getMembers()->contains($currentUser)) {
            throw $this->createAccessDeniedException($this->translator->trans('Non autorisé à accéder à ce fichier.'));
        }

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
     * HTML files are served as text/plain to prevent browser rendering.
     */
    private static function previewContentType(Message $message): string
    {
        $mimeType = $message->getMimeType();

        if ($mimeType === null || $mimeType === '') {
            return 'application/octet-stream';
        }

        return strtolower($mimeType) === 'text/html' ? 'text/plain' : $mimeType;
    }
}
