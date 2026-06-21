<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ScanFileMessage;
use App\Repository\MessageRepository;
use App\Service\ClamavService;
use App\Service\FileUploadService;
use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

#[AsMessageHandler]
class ScanFileMessageHandler
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly FileUploadService $fileUploadService,
        private readonly ClamavService $clamavService,
        private readonly EntityManagerInterface $em,
        private readonly MercurePublisher $mercurePublisher,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ScanFileMessage $message): void
    {
        $messageId = $message->getMessageId();
        $dbMessage = $this->messageRepository->find($messageId);

        if (!$dbMessage || !$dbMessage->getFilePath()) {
            return;
        }

        $this->logger->info(sprintf(
            'Starting async virus scan for message %d ("%s").',
            $messageId,
            $dbMessage->getFileName(),
        ));

        try {
            if (!$this->fileUploadService->exists($dbMessage->getFilePath())) {
                $dbMessage->setVirusScanStatus('failed');
                $this->em->flush();
                $this->publishUpdate($dbMessage);
                return;
            }

            $stream = $this->fileUploadService->readStream($dbMessage->getFilePath());
            $isClean = $this->clamavService->scanStream($stream, $dbMessage->getFileName());
            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($isClean) {
                $dbMessage->setVirusScanStatus('clean');
                $this->logger->info(sprintf('File "%s" (message %d) is clean.', $dbMessage->getFileName(), $messageId));
            } else {
                $dbMessage->setVirusScanStatus('infected');
                $this->logger->warning(sprintf(
                    'Virus detected in "%s" (message %d). Deleting file.',
                    $dbMessage->getFileName(),
                    $messageId,
                ));
                $this->fileUploadService->delete($dbMessage->getFilePath());
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('ClamAV scan failed for message %d: %s', $messageId, $e->getMessage()));
            $dbMessage->setVirusScanStatus('failed');
        }

        $this->em->flush();

        // Push real-time HTML update to all clients in the channel
        $this->publishUpdate($dbMessage);
    }

    private function publishUpdate(\App\Entity\Message $message): void
    {
        try {
            $channel = $message->getChannel();
            $html = $this->twig->render('dashboard/_feed_item.html.twig', [
                'author' => $message->getAuthor(),
                'message' => $message->getContent(),
                'timestamp' => $message->getCreatedAt(),
                'message_id' => $message->getId(),
                'updated_at' => $message->getUpdatedAt(),
                'fileName' => $message->getFileName(),
                'fileSize' => $message->getFileSize(),
                'filePath' => $message->getFilePath(),
                'mimeType' => $message->getMimeType(),
                'messageObject' => $message,
                'oob' => true, // Out of band swap!
            ]);

            $this->mercurePublisher->publishToChannel($channel, $html, 'message_' . $channel->getSlug());
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Failed to publish Mercure scan update for message %d: %s',
                $message->getId(),
                $e->getMessage(),
            ));
        }
    }
}
