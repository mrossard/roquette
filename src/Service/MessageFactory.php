<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class MessageFactory
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly FileUploadService $fileUploadService,
    ) {}

    /**
     * Builds and initializes a new Message entity.
     * If an uploaded file is provided, it is uploaded and attached to the message.
     */
    public function create(
        Channel $channel,
        User $author,
        string $messageText,
        ?UploadedFile $file = null,
        ?int $replyToId = null,
    ): Message {
        $message = new Message();
        $message->setAuthor($author);
        $message->setChannel($channel);

        if ($replyToId !== null && !$channel->isTodoList()) {
            $parentMessage = $this->messageRepository->find($replyToId);
            if ($parentMessage !== null && $parentMessage->getChannel()->getId() === $channel->getId()) {
                $message->setParentMessage($parentMessage);
            }
        }

        $message->setContent(trim($messageText) === '' ? null : $messageText);

        if ($file !== null) {
            $this->fileUploadService->uploadAndAttachToMessage($file, $message);
            $message->setVirusScanStatus('pending');
        }

        return $message;
    }
}
