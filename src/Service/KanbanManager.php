<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\KanbanColumn;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\KanbanColumnRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

class KanbanManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KanbanColumnRepository $kanbanColumnRepository,
        private readonly MercurePublisher $mercurePublisher,
        private readonly MessageRenderer $messageRenderer,
        private readonly TranslatorInterface $translator,
        private readonly ChannelAccessService $channelAccessService,
        private readonly \Twig\Environment $twig,
    ) {}


    public function initializeDefaultColumns(Channel $channel): void
    {
        if (!$channel->isTodoList()) {
            return;
        }

        $existing = $this->kanbanColumnRepository->findByChannelOrdered($channel);
        if (count($existing) > 0) {
            return;
        }

        $defaults = [
            ['name' => $this->translator->trans('À faire'), 'position' => 0, 'color' => null],
            ['name' => $this->translator->trans('En cours'), 'position' => 1, 'color' => null],
            ['name' => $this->translator->trans('Terminé'), 'position' => 2, 'color' => null],
        ];

        foreach ($defaults as $def) {
            $column = new KanbanColumn();
            $column->setChannel($channel);
            $column->setName($def['name']);
            $column->setPosition($def['position']);
            $column->setColor($def['color']);
            $this->entityManager->persist($column);
        }

        $this->entityManager->flush();
    }

    public function createColumn(Channel $channel, string $name, ?string $color, ?int $position, User $currentUser): KanbanColumn
    {
        $this->assertCanManageKanban($channel, $currentUser);

        $column = new KanbanColumn();
        $column->setChannel($channel);
        $column->setName($name);
        $column->setColor($color);
        $column->setPosition($position ?? $this->kanbanColumnRepository->getNextPosition($channel));

        $this->entityManager->persist($column);
        $this->entityManager->flush();

        $this->publishKanbanUpdate($channel);

        return $column;
    }

    public function renameColumn(KanbanColumn $column, string $name, User $currentUser): void
    {
        $this->assertCanManageKanban($column->getChannel(), $currentUser);

        $column->setName($name);
        $this->entityManager->flush();

        $this->publishKanbanUpdate($column->getChannel());
    }

    public function deleteColumn(KanbanColumn $column, User $currentUser): void
    {
        $channel = $column->getChannel();
        $this->assertCanManageKanban($channel, $currentUser);

        // Move messages to untriaged (kanbanColumn = null)
        foreach ($column->getMessages() as $message) {
            $message->setKanbanColumn(null);
        }

        $this->entityManager->remove($column);
        $this->entityManager->flush();

        $this->publishKanbanUpdate($channel);
    }

    /**
     * @param int[] $columnIds
     */
    public function reorderColumns(array $columnIds, User $currentUser): void
    {
        if ($columnIds === []) {
            return;
        }

        $columns = $this->kanbanColumnRepository->findBy(['id' => $columnIds]);
        if ($columns === []) {
            return;
        }

        $channel = $columns[0]->getChannel();
        $this->assertCanManageKanban($channel, $currentUser);

        $positionMap = array_flip($columnIds);
        foreach ($columns as $column) {
            $colId = $column->getId();
            if ($colId === null || !\array_key_exists($colId, $positionMap)) {
                continue;
            }

            $column->setPosition($positionMap[$colId]);
        }

        $this->entityManager->flush();
        $this->publishKanbanUpdate($channel);
    }

    public function moveMessageToColumn(Message $message, ?KanbanColumn $column, User $currentUser): void
    {
        $channel = $message->getChannel();
        $this->assertCanAccessKanban($channel, $currentUser);

        if ($column !== null && $column->getChannel()->getId() !== $channel->getId()) {
            throw new AccessDeniedHttpException($this->translator->trans('Cette colonne n\'appartient pas à ce canal.'));
        }

        $message->setKanbanColumn($column);

        $isCompleted = ($column !== null && in_array($column->getName(), ['Terminé', 'Done'], true));
        $message->setIsCompleted($isCompleted);

        $isCompleted
            ? $this->addCompletionReaction($message, $currentUser)
            : $this->removeCompletionReaction($message, $currentUser);
        $this->entityManager->flush();

        $this->publishKanbanCardMoved($message, $column);
    }

    public function assignMessage(Message $message, ?User $user, User $currentUser): void
    {
        $channel = $message->getChannel();
        $this->assertCanAccessKanban($channel, $currentUser);

        $message->setAssignedTo($user);
        $this->entityManager->flush();

        $this->publishKanbanCardUpdated($message);
    }

    public function setDueDate(Message $message, ?\DateTimeImmutable $dueAt, User $currentUser): void
    {
        $channel = $message->getChannel();
        $this->assertCanAccessKanban($channel, $currentUser);

        $message->setDueAt($dueAt);
        $this->entityManager->flush();

        $this->publishKanbanCardUpdated($message);
    }

    public function setPriority(Message $message, ?string $priority, User $currentUser): void
    {
        $channel = $message->getChannel();
        $this->assertCanAccessKanban($channel, $currentUser);

        $message->setPriority($priority);
        $this->entityManager->flush();

        $this->publishKanbanCardUpdated($message);
    }

    public function setLabels(Message $message, ?array $labels, User $currentUser): void
    {
        $channel = $message->getChannel();
        $this->assertCanAccessKanban($channel, $currentUser);

        $message->setLabels($labels);
        $this->entityManager->flush();

        $this->publishKanbanCardUpdated($message);
    }

    public function markAsCompleted(Message $message, User $currentUser): void
    {
        $channel = $message->getChannel();
        $this->assertCanAccessKanban($channel, $currentUser);

        $message->setIsCompleted(true);
        $this->addCompletionReaction($message, $currentUser);
        $this->entityManager->flush();

        $this->publishKanbanCardUpdated($message);
    }

    public function markAsIncomplete(Message $message, User $currentUser): void
    {
        $channel = $message->getChannel();
        $this->assertCanAccessKanban($channel, $currentUser);

        $message->setIsCompleted(false);
        $this->removeCompletionReaction($message, $currentUser);
        $this->entityManager->flush();

        $this->publishKanbanCardUpdated($message);
    }

    private function addCompletionReaction(Message $message, User $currentUser): void
    {
        $reactionRepo = $this->entityManager->getRepository(\App\Entity\Reaction::class);
        $existingCheck = $reactionRepo->findOneBy([
            'message' => $message,
            'user' => $currentUser,
            'emoji' => '✅',
        ]);

        if (!$existingCheck) {
            $reaction = new \App\Entity\Reaction();
            $reaction->setMessage($message);
            $reaction->setUser($currentUser);
            $reaction->setEmoji('✅');
            $this->entityManager->persist($reaction);
        }
    }

    private function removeCompletionReaction(Message $message, User $currentUser): void
    {
        $reactionRepo = $this->entityManager->getRepository(\App\Entity\Reaction::class);
        $existingCheck = $reactionRepo->findOneBy([
            'message' => $message,
            'user' => $currentUser,
            'emoji' => '✅',
        ]);

        if ($existingCheck !== null) {
            $this->entityManager->remove($existingCheck);
        }
    }

    private function assertCanManageKanban(Channel $channel, User $user): void
    {
        if (!$this->channelAccessService->canUserAccess($channel, $user)) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé.'));
        }

        $isWorkspaceCreator = $channel->getWorkspace() !== null && $channel->getWorkspace()->getCreator()?->getId() === $user->getId();
        if (!$user->isAdmin() && !$channel->isAdministrator($user) && !$isWorkspaceCreator && !($channel->getCreator()?->getId() === $user->getId())) {
            throw new AccessDeniedHttpException($this->translator->trans(
                'Seuls les administrateurs peuvent gérer les colonnes du tableau.',
            ));
        }
    }

    private function assertCanAccessKanban(Channel $channel, User $user): void
    {
        if (!$this->channelAccessService->canUserAccess($channel, $user)) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé.'));
        }
    }

    private function publishKanbanUpdate(Channel $channel): void
    {
        $this->mercurePublisher->publishToChannel($channel, [
            'type' => 'kanban_columns_changed',
            'channelSlug' => $channel->getSlug(),
        ], 'kanban_columns_changed');
    }

    private function publishKanbanCardMoved(Message $message, ?KanbanColumn $column): void
    {
        $channel = $message->getChannel();
        $renderedHtml = $this->messageRenderer->renderFeedItem($message, ['no_fade' => true]);
        $renderedHtmlOob = $this->messageRenderer->renderFeedItem($message, ['oob' => true]);

        $renderedKanbanCard = $this->twig->render('dashboard/_kanban_card.html.twig', [
            'message' => $message,
            'channel' => $channel,
        ]);

        $this->mercurePublisher->publishToChannel($channel, [
            'type' => 'kanban_card_moved',
            'messageId' => $message->getId(),
            'columnId' => $column?->getId(),
            'html' => $renderedHtml,
            'htmlOob' => $renderedHtmlOob,
            'kanbanCardHtml' => $renderedKanbanCard,
        ], 'kanban_card_moved');
    }

    private function publishKanbanCardUpdated(Message $message): void
    {
        $channel = $message->getChannel();
        $renderedHtmlOob = $this->messageRenderer->renderFeedItem($message, ['oob' => true]);

        $renderedKanbanCard = $this->twig->render('dashboard/_kanban_card.html.twig', [
            'message' => $message,
            'channel' => $channel,
        ]);

        $this->mercurePublisher->publishToChannel($channel, [
            'type' => 'kanban_card_updated',
            'messageId' => $message->getId(),
            'htmlOob' => $renderedHtmlOob,
            'kanbanCardHtml' => $renderedKanbanCard,
        ], 'kanban_card_updated');
    }
}
