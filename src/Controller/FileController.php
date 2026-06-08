<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\MessageRepository;
use App\Service\FileUploadService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class FileController extends AbstractController
{
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
            throw $this->createNotFoundException('Fichier non trouvé.');
        }

        $channel = $message->getChannel();
        if (($channel->isPrivate() || $channel->isDm()) && !$channel->getMembers()->contains($currentUser)) {
            throw $this->createAccessDeniedException('Non autorisé à accéder à ce fichier.');
        }

        if (!$fileUploadService->exists($message->getFilePath())) {
            throw $this->createNotFoundException('Le fichier n\'existe pas.');
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
                'Content-Type' => ($message->getMimeType() !== null && $message->getMimeType() !== '') ? $message->getMimeType() : 'application/octet-stream',
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT,
                    $message->getFileName(),
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
            throw $this->createNotFoundException('Fichier non trouvé.');
        }

        $channel = $message->getChannel();
        if (($channel->isPrivate() || $channel->isDm()) && !$channel->getMembers()->contains($currentUser)) {
            throw $this->createAccessDeniedException('Non autorisé à accéder à ce fichier.');
        }

        if (!$fileUploadService->exists($message->getFilePath())) {
            throw $this->createNotFoundException('Le fichier n\'existe pas.');
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
                'Content-Type' => ($message->getMimeType() !== null && $message->getMimeType() !== '') ? $message->getMimeType() : 'application/octet-stream',
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_INLINE,
                    $message->getFileName(),
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
            throw $this->createNotFoundException('Fichier non trouvé.');
        }

        $channel = $message->getChannel();
        if (($channel->isPrivate() || $channel->isDm()) && !$channel->getMembers()->contains($currentUser)) {
            throw $this->createAccessDeniedException('Non autorisé à accéder à ce fichier.');
        }

        if (!$fileUploadService->exists($message->getFilePath())) {
            throw $this->createNotFoundException('Le fichier n\'existe pas.');
        }

        $stream = $fileUploadService->readStream($message->getFilePath());
        $text = stream_get_contents($stream, 10000);

        $isTruncated = false;
        // If there is still at least one character to read, the content was truncated
        if (fgetc($stream) !== false) {
            $isTruncated = true;
        }

        if (is_resource($stream)) {
            fclose($stream);
        }

        if ($isTruncated) {
            $text .= "\n\n... [Contenu tronqué, téléchargez le fichier pour le lire en entier]";
        }

        $fileExt = pathinfo($message->getFileName(), PATHINFO_EXTENSION);

        return $this->render('dashboard/_text_preview.html.twig', [
            'message_id' => $message->getId(),
            'text' => $text,
            'fileExt' => $fileExt,
        ]);
    }

    #[Route('/messages/{id}/text-preview/hide', name: 'app_file_text_preview_hide', methods: ['GET'])]
    public function textPreviewHide(
        int $id,
        MessageRepository $messageRepository,
    ): Response {
        $message = $messageRepository->find($id);
        if (!$message) {
            throw $this->createNotFoundException('Message non trouvé.');
        }

        return $this->render('dashboard/_text_preview_button.html.twig', [
            'message_id' => $message->getId(),
        ]);
    }

    #[Route('/messages/{id}/lightbox', name: 'app_lightbox', methods: ['GET'])]
    public function lightbox(
        int $id,
        MessageRepository $messageRepository,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $message = $messageRepository->find($id);
        if (!$message || !$message->getFilePath()) {
            throw $this->createNotFoundException('Fichier non trouvé.');
        }

        $channel = $message->getChannel();
        if (($channel->isPrivate() || $channel->isDm()) && !$channel->getMembers()->contains($currentUser)) {
            throw $this->createAccessDeniedException('Non autorisé à accéder à ce fichier.');
        }

        return $this->render('_lightbox_content.html.twig', [
            'message_id' => $message->getId(),
            'fileName' => $message->getFileName(),
            'previewUrl' => $this->generateUrl('app_file_preview', ['id' => $message->getId()]),
            'downloadUrl' => $this->generateUrl('app_file_download', ['id' => $message->getId()]),
        ]);
    }
}
