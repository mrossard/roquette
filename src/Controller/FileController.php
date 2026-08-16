<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Entity\Message;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Service\FileStreamResponseFactory;
use App\Service\FileUploadService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class FileController extends AbstractController
{
    use ChannelAccessTrait;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly FileStreamResponseFactory $fileResponseFactory,
    ) {}

    #[Route('/messages/{id}/download', name: 'app_file_download', methods: ['GET'])]
    public function downloadFile(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        FileUploadService $fileUploadService,
    ): Response {
        $message = $this->findAndAuthorizeFileMessage($id, $messageRepository);

        return $this->fileResponseFactory->createMessageFileResponse(
            $message,
            $request,
            $fileUploadService,
            HeaderUtils::DISPOSITION_ATTACHMENT,
        );
    }

    #[Route('/messages/{id}/preview', name: 'app_file_preview', methods: ['GET'])]
    public function previewFile(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        FileUploadService $fileUploadService,
    ): Response {
        $message = $this->findAndAuthorizeFileMessage($id, $messageRepository);
        $disposition = FileStreamResponseFactory::isUnsafeForInlinePreview($message)
            ? HeaderUtils::DISPOSITION_ATTACHMENT
            : HeaderUtils::DISPOSITION_INLINE;

        return $this->fileResponseFactory->createMessageFileResponse(
            $message,
            $request,
            $fileUploadService,
            $disposition,
            FileStreamResponseFactory::getPreviewContentType($message),
        );
    }

    #[Route('/messages/{id}/text-preview', name: 'app_file_text_preview', methods: ['GET'])]
    public function textPreview(
        int $id,
        MessageRepository $messageRepository,
        FileUploadService $fileUploadService,
        Request $request,
    ): Response {
        $message = $this->findAndAuthorizeFileMessage($id, $messageRepository);

        if (!$fileUploadService->exists((string) $message->getFilePath())) {
            throw $this->createNotFoundException($this->translator->trans('Le fichier n\'existe pas.'));
        }

        $stream = $fileUploadService->readStream((string) $message->getFilePath());
        $text = stream_get_contents($stream, 10_000);

        $isTruncated = false;
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

        $fileExt = pathinfo((string) $message->getFileName(), PATHINFO_EXTENSION);
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

        $fileExt = pathinfo((string) $message->getFileName(), PATHINFO_EXTENSION);

        return $this->render('dashboard/_text_preview_button.html.twig', [
            'message_id' => $message->getId(),
            'fileExt' => $fileExt,
        ]);
    }

    #[Route('/messages/{id}/lightbox', name: 'app_lightbox', methods: ['GET'])]
    public function lightbox(int $id, MessageRepository $messageRepository): Response
    {
        $message = $this->findAndAuthorizeFileMessage($id, $messageRepository);

        return $this->render('modals/_lightbox_content.html.twig', [
            'message_id' => $message->getId(),
            'fileName' => $message->getFileName(),
            'previewUrl' => $this->generateUrl('app_file_preview', ['id' => $message->getId()]),
            'downloadUrl' => $this->generateUrl('app_file_download', ['id' => $message->getId()]),
        ]);
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
}
