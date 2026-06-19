<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\ChannelExport;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\AuditAction;
use Doctrine\ORM\EntityManagerInterface;
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
        $messages = $this->entityManager->getRepository(Message::class)->findBy(
            ['channel' => $channel],
            ['createdAt' => 'ASC'],
        );

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
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
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
        $zipFile = tempnam(sys_get_temp_dir(), 'export-');
        if ($zipFile === false || $zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
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
                        $zip->addFile($tmpFile, 'files/' . basename($filePath));
                        $tmpFiles[] = $tmpFile;
                    }
                } catch (\Exception) {
                    continue;
                }
            }
        } finally {
            $zip->close();
        }

        $filename = $channel->getSlug() . '-export.zip';

        $this->saveAndCreateExportEntity($channel, $currentUser, $filename, $zipFile, 'zip');

        foreach ($tmpFiles as $tmpFile) {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        return new StreamedResponse(function () use ($zipFile) {
            $out = fopen('php://output', 'wb');
            $in = fopen($zipFile, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            unlink($zipFile);
        }, Response::HTTP_OK, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
            ),
        ]);
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

        return new StreamedResponse(function () use ($tarFile) {
            $out = fopen('php://output', 'wb');
            $in = fopen($tarFile, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            unlink($tarFile);
        }, Response::HTTP_OK, [
            'Content-Type' => 'application/x-tar',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
            ),
        ]);
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
        $this->fileUploadService->writeStream($storagePath, $stream);

        $export = new ChannelExport();
        $export->setChannel($channel);
        $export->setExportedBy($currentUser);
        $export->setFileName($filename);
        $export->setFilePath($storagePath);
        $export->setFileSize(filesize($tempFilePath));
        $export->setChannelName($channel->getName());

        $this->entityManager->persist($export);
        $this->entityManager->flush();

        $this->auditLogger->log(
            AuditAction::CHANNEL_EXPORT,
            $currentUser,
            [
                'channel_id' => $channel->getId(),
                'channel_name' => $channel->getName(),
                'slug' => $channel->getSlug(),
                'export_id' => $export->getId(),
                'file_name' => $filename,
            ],
        );
    }
}
