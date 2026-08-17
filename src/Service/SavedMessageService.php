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

class SavedMessageService
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ChannelAccessService $channelAccessService,
        private readonly TranslatorInterface $translator,
    ) {}

    public function toggleSave(int $id, User $currentUser): Message
    {
        $message = $this->findMessage($id);

        $channel = $message->getChannel();
        if ($channel === null || !$this->channelAccessService->canUserAccess($channel, $currentUser)) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé.'));
        }

        if ($currentUser->getSavedMessages()->contains($message)) {
            $currentUser->removeSavedMessage($message);
        } else {
            $currentUser->addSavedMessage($message);
        }

        $this->entityManager->flush();

        return $message;
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
