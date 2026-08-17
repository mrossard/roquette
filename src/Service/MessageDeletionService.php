<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

class MessageDeletionService
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBroadcaster $messageBroadcaster,
        private readonly FileUploadService $fileUploadService,
        private readonly TranslatorInterface $translator,
    ) {}

    public function delete(int $id, User $currentUser): void
    {
        $message = $this->findMessage($id);
        $channel = $message->getChannel();

        if (
            $message->getAuthor() !== $currentUser
            && $channel->getCreator() !== $currentUser
            && !in_array('ROLE_ADMIN', $currentUser->getRoles(), true)
        ) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé à supprimer ce message.'));
        }

        $extraOobHtml = '';

        if ($channel->getPinnedMessage() === $message) {
            $channel->setPinnedMessage(null);
            $extraOobHtml .= '<div id="pinned-banner-container" hx-swap-oob="true"></div>';
        }

        if ($message->getFilePath()) {
            $this->fileUploadService->delete($message->getFilePath());
        }

        $wasPendingModeration = $message->getModerationStatus() !== null && $message->getModerationStatus() !== 'clean';

        $this->entityManager->remove($message);
        $this->entityManager->flush();

        if ($wasPendingModeration) {
            $this->messageBroadcaster->publishCurrentModerationCount();
        }

        $this->messageBroadcaster->broadcastMessageDeletion(
            $channel,
            $id,
            $extraOobHtml !== '' ? $extraOobHtml : null,
        );
    }

    private function findMessage(int $id): Message
    {
        $message = $this->messageRepository->find($id);
        if (!$message) {
            throw new NotFoundHttpException($this->translator->trans('Message non trouvé.'));
        }

        return $message;
    }
}
