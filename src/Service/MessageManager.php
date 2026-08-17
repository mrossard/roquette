<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Message\EditMessageDto;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

class MessageManager
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly MessageEditor $messageEditor,
        private readonly MessageDeletionService $messageDeletionService,
        private readonly SavedMessageService $savedMessageService,
        private readonly TranslatorInterface $translator,
    ) {}

    public function editMessageForm(int $id, User $currentUser): Message
    {
        return $this->messageEditor->getEditableMessage($id, $currentUser);
    }

    /**
     * @param list<string> $pollOptions
     */
    public function editMessage(
        int $id,
        User $currentUser,
        string $newContent = '',
        ?string $pollQuestion = null,
        array $pollOptions = [],
        bool $pollAllowMultiple = false,
    ): string {
        $dto = new EditMessageDto(
            content: $newContent,
            pollQuestion: $pollQuestion,
            pollOptions: $pollOptions,
            allowMultiple: $pollAllowMultiple,
        );

        return $this->messageEditor->edit($id, $currentUser, $dto);
    }

    /**
     * @return array{success: true}
     */
    public function deleteMessage(int $id, User $currentUser): array
    {
        $this->messageDeletionService->delete($id, $currentUser);

        return ['success' => true];
    }

    public function toggleSaveMessage(int $id, User $currentUser): Message
    {
        return $this->savedMessageService->toggleSave($id, $currentUser);
    }

    public function findMessage(int $id): Message
    {
        $message = $this->messageRepository->find($id);
        if (!$message) {
            throw new NotFoundHttpException($this->translator->trans('Message non trouvé.'));
        }

        return $message;
    }
}
