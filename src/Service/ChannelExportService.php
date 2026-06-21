<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\ChannelExport;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\AuditAction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

class ChannelExportService
{
    public function __construct(
        private readonly FileUploadService $fileUploadService,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLoggerService $auditLogger,
        private readonly TranslatorInterface $translator,
        private readonly \Twig\Environment $twig,
    ) {}

    public function export(Channel $channel, User $currentUser): Response
    {
        $messages = $this->entityManager->getRepository(Message::class)->findBy(['channel' => $channel], [
            'createdAt' => 'ASC',
        ]);

        $exportData = [
            'channel' => [
                'id' => $channel->getId(),
                'name' => $channel->getName(),
                'slug' => $channel->getSlug(),
                'description' => $channel->getDescription(),
                'createdAt' => $channel->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'isPrivate' => $channel->isPrivate(),
                'isTodoList' => $channel->isTodoList(),
            ],
            'exportedAt' => new \DateTimeImmutable()->format(\DateTimeInterface::ATOM),
            'exportedBy' => $currentUser->getUsername(),
            'messages' => [],
        ];

        foreach ($messages as $msg) {
            $msgData = [
                'id' => $msg->getId(),
                'author' => [
                    'username' => $msg->getAuthor()?->getUsername(),
                    'displayName' => $msg->getAuthor()?->getDisplayName(),
                ],
                'content' => $msg->getContent(),
                'formattedContent' => $msg->getFormattedContent(),
                'createdAt' => $msg->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $msg->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            ];

            if ($msg->getFileName()) {
                $msgData['file'] = [
                    'name' => $msg->getFileName(),
                    'size' => $msg->getFileSize(),
                    'mimeType' => $msg->getMimeType(),
                    'path' => 'files/' . basename($msg->getFilePath()),
                ];
            }

            $exportData['messages'][] = $msgData;
        }

        $htmlContent = $this->twig->render('dashboard/export.html.twig', [
            'channel' => $channel,
            'messages' => $messages,
            'exportData' => $exportData,
        ]);

        if (class_exists(\ZipArchive::class)) {
            return $this->exportAsZip($channel, $currentUser, $exportData, $htmlContent, $messages);
        }

        return $this->exportAsTar($channel, $currentUser, $exportData, $htmlContent, $messages);
    }

    private function exportAsZip(
        Channel $channel,
        User $currentUser,
        array $exportData,
        string $htmlContent,
        array $messages,
    ): Response {
        $zip = new \ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'export-');
        $zipFile = $tempFile . '.zip';
        if ($tempFile !== false && file_exists($tempFile)) {
            unlink($tempFile);
        }
        if ($zipFile === false || $zip->open($zipFile, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException($this->translator->trans('Impossible de créer l\'archive ZIP.'));
        }

        $zip->addFromString('channel.json', json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('channel.html', $htmlContent);

        $tmpFiles = [];
        try {
            foreach ($messages as $msg) {
                $filePath = $msg->getFilePath();
                if (!$filePath) {
                    continue;
                }
                try {
                    if ($this->fileUploadService->exists($filePath)) {
                        $fileStream = $this->fileUploadService->readStream($filePath);
                        $tmpFile = tempnam(sys_get_temp_dir(), 'attach-');
                        $tmpStream = fopen($tmpFile, 'wb');
                        stream_copy_to_stream($fileStream, $tmpStream);
                        fclose($tmpStream);
                        if (is_resource($fileStream)) {
                            fclose($fileStream);
                        }
                        if ($zip->addFile($tmpFile, 'files/' . basename($filePath)) !== true) {
                            throw new \RuntimeException('Failed to add attachment to ZIP: ' . $tmpFile);
                        }
                        $tmpFiles[] = $tmpFile;
                    }
                } catch (\Exception $e) {
                    throw new \RuntimeException('Error adding file to ZIP: ' . $e->getMessage(), 0, $e);
                }
            }
        } finally {
            $status = $zip->getStatusString();
            $closed = $zip->close();
            if (!$closed) {
                throw new \RuntimeException('ZipArchive::close() failed. Status: ' . $status);
            }
        }

        if (!file_exists($zipFile)) {
            throw new \RuntimeException('Zip file does not exist after closing: ' . $zipFile);
        }

        $size = filesize($zipFile);
        if ($size === 0) {
            throw new \RuntimeException('Zip file is empty (0 bytes) after closing: ' . $zipFile);
        }

        $filename = $channel->getSlug() . '-export.zip';

        $this->saveAndCreateExportEntity($channel, $currentUser, $filename, $zipFile, 'zip');

        foreach ($tmpFiles as $tmpFile) {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        $response = new BinaryFileResponse($zipFile);
        $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Type', 'application/zip');
        $response->deleteFileAfterSend(true);

        return $response;
    }

    private function exportAsTar(
        Channel $channel,
        User $currentUser,
        array $exportData,
        string $htmlContent,
        array $messages,
    ): Response {
        $tarFile = tempnam(sys_get_temp_dir(), 'export-') . '.tar';
        $tar = new \PharData($tarFile);

        $tar->addFromString('channel.json', json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $tar->addFromString('channel.html', $htmlContent);

        $tmpFiles = [];
        try {
            foreach ($messages as $msg) {
                $filePath = $msg->getFilePath();
                if (!$filePath) {
                    continue;
                }
                try {
                    if ($this->fileUploadService->exists($filePath)) {
                        $fileStream = $this->fileUploadService->readStream($filePath);
                        $tmpFile = tempnam(sys_get_temp_dir(), 'attach-');
                        $tmpStream = fopen($tmpFile, 'wb');
                        stream_copy_to_stream($fileStream, $tmpStream);
                        fclose($tmpStream);
                        if (is_resource($fileStream)) {
                            fclose($fileStream);
                        }
                        $tar->addFile($tmpFile, 'files/' . basename($filePath));
                        $tmpFiles[] = $tmpFile;
                    }
                } catch (\Exception) {
                    continue;
                }
            }
        } finally {
            // PharData writes on object destruction; force it
            unset($tar);
        }

        $filename = $channel->getSlug() . '-export.tar';

        $this->saveAndCreateExportEntity($channel, $currentUser, $filename, $tarFile, 'tar');

        foreach ($tmpFiles as $tmpFile) {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        $response = new BinaryFileResponse($tarFile);
        $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Type', 'application/x-tar');
        $response->deleteFileAfterSend(true);

        return $response;
    }

    private function saveAndCreateExportEntity(
        Channel $channel,
        User $currentUser,
        string $filename,
        string $tempFilePath,
        string $extension,
    ): void {
        $uniqueFilename = $channel->getSlug() . '-' . uniqid() . '.' . $extension;
        $storagePath = 'exports/' . $uniqueFilename;

        $stream = fopen($tempFilePath, 'rb');
        try {
            $this->fileUploadService->writeStream($storagePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $export = new ChannelExport();
        $export->setChannel($channel);
        $export->setExportedBy($currentUser);
        $export->setFileName($filename);
        $export->setFilePath($storagePath);
        $export->setFileSize(filesize($tempFilePath));
        $export->setChannelName($channel->getName());

        $this->entityManager->persist($export);
        $this->entityManager->flush();

        $this->auditLogger->log(AuditAction::CHANNEL_EXPORT, $currentUser, [
            'channel_id' => $channel->getId(),
            'channel_name' => $channel->getName(),
            'slug' => $channel->getSlug(),
            'export_id' => $export->getId(),
            'file_name' => $filename,
        ]);
    }
}
