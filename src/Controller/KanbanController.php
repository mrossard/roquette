<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Dto\Kanban\CreateKanbanColumnDto;
use App\Dto\Kanban\ReorderKanbanColumnsDto;
use App\Dto\Kanban\UpdateKanbanCardDto;
use App\Dto\Kanban\UpdateKanbanColumnDto;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\KanbanColumnRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\ChannelManager;
use App\Service\KanbanManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class KanbanController extends AbstractController
{
    use ChannelAccessTrait;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly KanbanManager $kanbanManager,
        private readonly KanbanColumnRepository $kanbanColumnRepository,
        private readonly MessageRepository $messageRepository,
        private readonly ChannelManager $channelManager,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly \App\Service\DashboardViewBuilder $dashboardViewBuilder,
    ) {}

    #[Route('/channels/{slug}/kanban', name: 'app_channel_kanban', methods: ['GET'])]
    public function kanbanBoard(string $slug, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $channel = $this->channelManager->findChannelBySlug($slug);
        if (!$channel->isTodoList()) {
            throw $this->createNotFoundException($this->translator->trans(
                'Canal non trouvé ou non configuré en todo.',
            ));
        }

        $this->authorizeChannelAccess($channel);

        $columns = $this->kanbanColumnRepository->findByChannelWithMessages($channel);
        $untriagedMessages = $this->messageRepository->findUntriagedByChannel($channel);
        $members = $channel->getMembers()->toArray();

        if (
            $request->headers->has('HX-Request')
            && in_array($request->headers->get('HX-Target'), ['live-feed', 'kanban-board'], true)
        ) {
            return $this->render('dashboard/_kanban_board.html.twig', [
                'channel' => $channel,
                'kanbanColumns' => $columns,
                'untriagedMessages' => $untriagedMessages,
                'members' => $members,
            ]);
        }

        $viewContext = $this->dashboardViewBuilder->buildKanbanViewContext(
            $currentUser,
            $channel,
            $columns,
            $untriagedMessages,
            $members,
        );

        return $this->render('dashboard/index.html.twig', $viewContext);
    }

    #[Route('/kanban/columns', name: 'app_kanban_column_create', methods: ['POST'])]
    public function createColumn(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $dto = CreateKanbanColumnDto::fromRequest($request);

        $channel = $this->entityManager->getRepository(Channel::class)->find($dto->channelId);
        if (!$channel) {
            return new Response($this->translator->trans('Canal non trouvé.'), 404);
        }

        $this->denyAccessUnlessGranted('MANAGE', $channel);

        if ($dto->name === '') {
            return new Response($this->translator->trans('Le nom de la colonne est requis.'), 400);
        }

        try {
            $this->kanbanManager->createColumn($channel, $dto->name, $dto->color, null, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return $this->redirectToRoute('app_channel_kanban', ['slug' => $channel->getSlug()]);
    }

    #[Route('/kanban/columns/{id}/rename', name: 'app_kanban_column_rename', methods: ['POST'])]
    public function renameColumn(int $id, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $column = $this->kanbanColumnRepository->find($id);
        if (!$column) {
            return new Response($this->translator->trans('Colonne non trouvée.'), 404);
        }

        $this->denyAccessUnlessGranted('MANAGE', $column->getChannel());

        $dto = UpdateKanbanColumnDto::fromRequest($request);
        if (!$dto->isValid()) {
            return new Response($this->translator->trans('Le nom de la colonne est requis.'), 400);
        }

        try {
            $this->kanbanManager->renameColumn($column, $dto->name, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return $this->render('dashboard/_kanban_column_header.html.twig', [
            'column' => $column,
            'channel' => $column->getChannel(),
        ]);
    }

    #[Route('/kanban/columns/{id}/delete', name: 'app_kanban_column_delete', methods: ['POST'])]
    public function deleteColumn(int $id): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $column = $this->kanbanColumnRepository->find($id);
        if (!$column) {
            return new Response($this->translator->trans('Colonne non trouvée.'), 404);
        }

        $channel = $column->getChannel();
        $this->denyAccessUnlessGranted('MANAGE', $channel);

        try {
            $this->kanbanManager->deleteColumn($column, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return $this->redirectToRoute('app_channel_kanban', ['slug' => $channel->getSlug()]);
    }

    #[Route('/kanban/columns/reorder', name: 'app_kanban_columns_reorder', methods: ['POST'])]
    public function reorderColumns(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $dto = ReorderKanbanColumnsDto::fromRequest($request);
        if (!$dto->isValid()) {
            return new Response($this->translator->trans('Paramètres invalides.'), 400);
        }

        if ($dto->columnIds === []) {
            return new Response(null, 204);
        }

        $firstCol = $this->kanbanColumnRepository->find($dto->columnIds[0]);
        if ($firstCol !== null) {
            $this->denyAccessUnlessGranted('MANAGE', $firstCol->getChannel());
        }

        try {
            $this->kanbanManager->reorderColumns($dto->columnIds, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return new Response(null, 204);
    }

    #[Route('/messages/{id}/kanban-column', name: 'app_kanban_move_message', methods: ['POST'])]
    public function moveMessage(Message $message, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $columnId = UpdateKanbanCardDto::parseColumnId($request);
        $column = $columnId !== null ? $this->kanbanColumnRepository->find($columnId) : null;

        try {
            $this->kanbanManager->moveMessageToColumn($message, $column, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return $this->renderKanbanCard($message);
    }

    #[Route('/messages/{id}/assign', name: 'app_kanban_assign', methods: ['POST'])]
    public function assignMessage(Message $message, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $userId = UpdateKanbanCardDto::parseUserId($request);
        $user = $userId !== null ? $this->userRepository->find($userId) : null;

        try {
            $this->kanbanManager->assignMessage($message, $user, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return $this->renderKanbanCard($message);
    }

    #[Route('/messages/{id}/due-date', name: 'app_kanban_due_date', methods: ['POST'])]
    public function setDueDate(Message $message, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $dueAt = UpdateKanbanCardDto::parseDueDate($request);

        try {
            $this->kanbanManager->setDueDate($message, $dueAt, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return $this->renderKanbanCard($message);
    }

    #[Route('/messages/{id}/priority', name: 'app_kanban_priority', methods: ['POST'])]
    public function setPriority(Message $message, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $priority = UpdateKanbanCardDto::parsePriority($request);

        try {
            $this->kanbanManager->setPriority($message, $priority, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return $this->renderKanbanCard($message);
    }

    #[Route('/messages/{id}/labels', name: 'app_kanban_labels', methods: ['POST'])]
    public function setLabels(Message $message, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $labels = UpdateKanbanCardDto::parseLabels($request);

        try {
            $this->kanbanManager->setLabels($message, $labels, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return $this->renderKanbanCard($message);
    }

    #[Route('/messages/{id}/kanban-complete', name: 'app_kanban_toggle_complete', methods: ['POST'])]
    public function toggleComplete(Message $message): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $message->isCompleted()
                ? $this->kanbanManager->markAsIncomplete($message, $currentUser)
                : $this->kanbanManager->markAsCompleted($message, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return $this->renderKanbanCard($message);
    }

    private function renderKanbanCard(Message $message): Response
    {
        return $this->render('dashboard/_kanban_card.html.twig', [
            'message' => $message,
            'channel' => $message->getChannel(),
        ]);
    }
}
