<?php

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
class FileController extends AbstractController
{
    #[Route('/messages/{id}/download', name: 'app_file_download', methods: ['GET'])]
    public function downloadFile(
        int $id,
        MessageRepository $messageRepository,
        FileUploadService $fileUploadService
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

        return new StreamedResponse(static function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type'        => $message->getMimeType() ?: 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $message->getFileName()
            ),
        ]);
    }

    #[Route('/messages/{id}/preview', name: 'app_file_preview', methods: ['GET'])]
    public function previewFile(
        int $id,
        MessageRepository $messageRepository,
        FileUploadService $fileUploadService
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

        return new StreamedResponse(static function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type'        => $message->getMimeType() ?: 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $message->getFileName()
            ),
        ]);
    }
}
