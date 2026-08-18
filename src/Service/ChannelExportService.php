<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\ChannelExport;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Service\Export\ArchiveExporterInterface;
use App\Service\Export\TarArchiveExporter;
use App\Service\Export\ZipArchiveExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

readonly class ChannelExportService
{
    private ArchiveExporterInterface $archiveExporter;

    public function __construct(
        private FileUploadService $fileUploadService,
        private EntityManagerInterface $entityManager,
        private AuditLoggerService $auditLogger,
        private TranslatorInterface $translator,
        private Environment $twig,
        ?ArchiveExporterInterface $archiveExporter = null,
    ) {
        $this->archiveExporter = $archiveExporter ?? $this->resolveDefaultExporter();
    }

    public function generate(Channel $channel, User $currentUser): ChannelExport
    {
        $messages = $this->entityManager->getRepository(Message::class)->findBy(['channel' => $channel], [
            'createdAt' => 'ASC',
        ]);

        $exportData = $this->buildExportData($channel, $currentUser, $messages);

        $htmlContent = $this->twig->render('dashboard/export.html.twig', [
            'channel' => $channel,
            'messages' => $messages,
            'exportData' => $exportData,
        ]);

        $stringEntries = [
            'channel.json' => (string) json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'channel.html' => $htmlContent,
        ];

        $streamEntries = $this->collectAttachmentStreams($messages);

        try {
            $tempArchivePath = $this->archiveExporter->createArchive($stringEntries, $streamEntries);
        } finally {
            $this->closeStreams($streamEntries);
        }

        try {
            $filename = $channel->getSlug() . '-export.' . $this->archiveExporter->getExtension();
            return $this->saveAndCreateExportEntity(
                $channel,
                $currentUser,
                $filename,
                $tempArchivePath,
                $this->archiveExporter->getExtension(),
            );
        } finally {
            if (file_exists($tempArchivePath)) {
                unlink($tempArchivePath);
            }
        }
    }

    /**
     * @param Message[] $messages
     * @return array<string, resource> relative path => stream
     */
    private function collectAttachmentStreams(array $messages): array
    {
        $streams = [];
        foreach ($messages as $msg) {
            $filePath = $msg->getFilePath();
            if (!$filePath || !$this->fileUploadService->exists($filePath)) {
                continue;
            }

            try {
                $fileStream = $this->fileUploadService->readStream($filePath);
                if (is_resource($fileStream)) {
                    $streams['files/' . basename($filePath)] = $fileStream;
                }
            } catch (\Exception $e) {
                // @mago-expect no-empty-catch-clause - Continue with other attachments if one stream fails
                unset($e);
            }
        }

        return $streams;
    }

    /**
     * @param array<string, resource> $streams
     */
    private function closeStreams(array $streams): void
    {
        foreach ($streams as $stream) {
            if (!is_resource($stream)) {
                continue;
            }

            fclose($stream);
        }
    }

    /**
     * @param Message[] $messages
     * @return array<string, mixed>
     */
    private function buildExportData(Channel $channel, User $currentUser, array $messages): array
    {
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
                    'path' => 'files/' . basename((string) $msg->getFilePath()),
                ];
            }

            $exportData['messages'][] = $msgData;
        }

        return $exportData;
    }

    private function resolveDefaultExporter(): ArchiveExporterInterface
    {
        $zipExporter = new ZipArchiveExporter($this->translator);
        if ($zipExporter->isSupported()) {
            return $zipExporter;
        }

        return new TarArchiveExporter();
    }

    private function saveAndCreateExportEntity(
        Channel $channel,
        User $currentUser,
        string $filename,
        string $tempFilePath,
        string $extension,
    ): ChannelExport {
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
        $export->setFileSize((int) filesize($tempFilePath));
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

        return $export;
    }
}
