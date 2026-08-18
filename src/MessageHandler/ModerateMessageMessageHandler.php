<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\AuditAction;
use App\Enum\ModerationStatus;
use App\Message\ModerateMessageMessage;
use App\Repository\MessageRepository;
use App\Service\AuditLoggerService;
use App\Service\ContentModerationService;
use App\Service\MessageBroadcaster;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ModerateMessageMessageHandler
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly ContentModerationService $moderationService,
        private readonly EntityManagerInterface $em,
        private readonly MessageBroadcaster $messageBroadcaster,
        private readonly LoggerInterface $logger,
        private readonly ?AuditLoggerService $auditLogger = null,
    ) {}

    public function __invoke(ModerateMessageMessage $message): void
    {
        $messageId = $message->getMessageId();
        $dbMessage = $this->messageRepository->find($messageId);

        if (!$dbMessage || !$dbMessage->getContent() || $dbMessage->getChannel()?->isDm()) {
            return;
        }

        $this->logger->info(sprintf('Starting content moderation scan for message %d.', $messageId));

        try {
            $wasFlagged =
                $dbMessage->getModerationStatus() !== null
                && $dbMessage->getModerationStatus() !== ModerationStatus::CLEAN->value;
            $result = $this->moderationService->moderate($dbMessage->getContent());

            if (!$result->isFlagged()) {
                $dbMessage->setModerationStatus(ModerationStatus::CLEAN->value);
                $this->em->flush();
                if ($wasFlagged) {
                    $this->messageBroadcaster->publishCurrentModerationCount();
                }
                return;
            }

            $dbMessage->setOriginalContent($dbMessage->getContent());
            $dbMessage->setModerationStatus($result->getStatus());
            $dbMessage->setModerationReason($result->getReason());

            if ($result->isMasked() && $result->getMaskedContent() !== null) {
                $dbMessage->setContent($result->getMaskedContent());
            }

            $this->logger->warning(sprintf(
                'Message %d moderated: status=%s, reason="%s".',
                $messageId,
                $result->getStatus(),
                $result->getReason() ?? '',
            ));

            $this->auditLogger?->log(AuditAction::MESSAGE_MODERATED, $dbMessage->getAuthor(), [
                'message_id' => $messageId,
                'channel_slug' => $dbMessage->getChannel()->getSlug(),
                'status' => $result->getStatus(),
                'reason' => $result->getReason(),
            ]);

            $this->em->flush();
            $this->messageBroadcaster->broadcastMessageUpdate($dbMessage);
            $this->messageBroadcaster->publishCurrentModerationCount();
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Moderation scan failed for message %d: %s', $messageId, $e->getMessage()));
        }
    }
}
