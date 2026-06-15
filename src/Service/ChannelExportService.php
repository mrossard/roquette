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

        foreach ($messages as $msg) {
            $filePath = $msg->getFilePath();
            if (!$filePath) {
                continue;
            }
            try {
                if ($this->fileUploadService->exists($filePath)) {
                    $fileStream = $this->fileUploadService->readStream($filePath);
                    $fileContent = stream_get_contents($fileStream);
                    if (is_resource($fileStream)) {
                        fclose($fileStream);
                    }
                    $zip->addFromString('files/' . basename($filePath), $fileContent);
                }
            } catch (\Exception) {
                continue;
            }
        }

        $zip->close();
        $fileContentResult = file_get_contents($zipFile);
        unlink($zipFile);

        $filename = $channel->getSlug() . '-export.zip';

        $this->saveAndCreateExportEntity($channel, $currentUser, $filename, $fileContentResult, 'zip');

        $response = new Response($fileContentResult);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
            ),
        );

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

        foreach ($messages as $msg) {
            $filePath = $msg->getFilePath();
            if (!$filePath) {
                continue;
            }
            try {
                if ($this->fileUploadService->exists($filePath)) {
                    $fileStream = $this->fileUploadService->readStream($filePath);
                    $fileContent = stream_get_contents($fileStream);
                    if (is_resource($fileStream)) {
                        fclose($fileStream);
                    }
                    $tar->addFromString('files/' . basename($filePath), $fileContent);
                }
            } catch (\Exception) {
                continue;
            }
        }

        $fileContentResult = file_get_contents($tarFile);
        unlink($tarFile);

        $filename = $channel->getSlug() . '-export.tar';

        $this->saveAndCreateExportEntity($channel, $currentUser, $filename, $fileContentResult, 'tar');

        $response = new Response($fileContentResult);
        $response->headers->set('Content-Type', 'application/x-tar');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
            ),
        );

        return $response;
    }

    private function saveAndCreateExportEntity(
        Channel $channel,
        User $currentUser,
        string $filename,
        string $fileContentResult,
        string $extension,
    ): void {
        $uniqueFilename = $channel->getSlug() . '-' . uniqid() . '.' . $extension;
        $storagePath = 'exports/' . $uniqueFilename;

        $this->fileUploadService->write($storagePath, $fileContentResult);

        $export = new ChannelExport();
        $export->setChannel($channel);
        $export->setExportedBy($currentUser);
        $export->setFileName($filename);
        $export->setFilePath($storagePath);
        $export->setFileSize(strlen($fileContentResult));
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
