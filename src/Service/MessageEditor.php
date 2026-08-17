<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Message\EditMessageDto;
use App\Dto\Message\EditResult;
use App\Entity\Message;
use App\Entity\User;
use App\Message\ModerateMessageMessage;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class MessageEditor
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBroadcaster $messageBroadcaster,
        private readonly MessageRenderer $messageRenderer,
        private readonly PollFactory $pollFactory,
        private readonly TranslatorInterface $translator,
        private readonly ?MessageBusInterface $messageBus = null,
    ) {}

    public function getEditableMessage(int $id, User $currentUser): Message
    {
        $message = $this->findMessage($id);

        if ($message->getAuthor() !== $currentUser) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé à modifier ce message.'));
        }

        if ($message->isPoll() && $message->getPoll()?->getTotalVotes() > 0) {
            throw new BadRequestHttpException($this->translator->trans(
                'Impossible de modifier un sondage qui a déjà des votes.',
            ));
        }

        return $message;
    }

    public function edit(int $id, User $currentUser, EditMessageDto $dto): EditResult
    {
        $message = $this->findMessage($id);

        if ($message->getAuthor() !== $currentUser) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé à modifier ce message.'));
        }

        if ($message->isPoll()) {
            if ($message->getPoll()?->getTotalVotes() > 0) {
                return EditResult::error(
                    $this->translator->trans('Impossible de modifier un sondage qui a déjà des votes.'),
                    $message,
                    400,
                );
            }

            try {
                $this->pollFactory->updatePoll(
                    $message->getPoll(),
                    (string) $dto->pollQuestion,
                    $dto->pollOptions,
                    $dto->allowMultiple,
                );
            } catch (\InvalidArgumentException $e) {
                return EditResult::error($this->translator->trans($e->getMessage()), $message, 400);
            }
        } else {
            $newContent = $dto->content;
            if (trim((string) $newContent) === '' && !$message->getFilePath()) {
                return EditResult::error($this->translator->trans('Le message ne peut pas être vide.'), $message, 400);
            }

            $message->setContent(trim((string) $newContent) === '' ? null : $newContent);
        }

        $message->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $renderedHtml = $this->messageRenderer->renderFeedItem($message, ['no_fade' => true]);
        $this->messageBroadcaster->broadcastMessageUpdate($message);

        if ($message->getContent() !== null && !$message->isPoll() && !$message->getChannel()?->isDm()) {
            $this->messageBus?->dispatch(new ModerateMessageMessage($message->getId()));
        }

        return EditResult::ok($message, $renderedHtml);
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
