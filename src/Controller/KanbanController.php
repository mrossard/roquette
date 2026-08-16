<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\KanbanColumnRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\KanbanManager;
use App\Service\SidebarDataProvider;
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
        private readonly ChannelRepository $channelRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SidebarDataProvider $sidebarDataProvider,
    ) {}

    #[Route('/channels/{slug}/kanban', name: 'app_channel_kanban', methods: ['GET'])]
    public function kanbanBoard(
        string $slug,
        Request $request,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $channel = $this->channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel || !$channel->isTodoList()) {
            throw $this->createNotFoundException($this->translator->trans('Canal non trouvé ou non configuré en todo.'));
        }

        $this->authorizeChannelAccess($channel);

        $columns = $this->kanbanColumnRepository->findByChannelWithMessages($channel);
        $untriagedMessages = $this->messageRepository->findUntriagedByChannel($channel);
        $members = $channel->getMembers()->toArray();

        if ($request->headers->has('HX-Request') && in_array($request->headers->get('HX-Target'), ['live-feed', 'kanban-board'], true)) {
            return $this->render('dashboard/_kanban_board.html.twig', [
                'channel' => $channel,
                'kanbanColumns' => $columns,
                'untriagedMessages' => $untriagedMessages,
                'members' => $members,
            ]);
        }

        // Full page render: reuse channel controller data
        $sidebarData = $this->sidebarDataProvider->getSidebarData($currentUser);
        $unreadCounts = $sidebarData['unreadCounts'];
        $activeRead = $unreadCounts[$channel->getId()] ?? null;
        $notificationsEnabled = $activeRead['notificationsEnabled'] ?? $channel->isDm();

        return $this->render('dashboard/index.html.twig', array_merge([
            'activeChannel' => $channel,
            'messages' => [],
            'topic_url' => '',
            'firstUnreadMessageId' => null,
            'usersToInvite' => [],
            'isMember' => true,
            'notificationsEnabled' => $notificationsEnabled,
            'typingUsers' => [],
            'replyCounts' => [],
            'subchannelByParentMessageId' => [],
            'lastMessages' => [],
            'kanbanView' => true,
            'kanbanColumns' => $columns,
            'untriagedMessages' => $untriagedMessages,
            'kanbanMembers' => $members,
        ], $sidebarData));
    }

    #[Route('/kanban/columns', name: 'app_kanban_column_create', methods: ['POST'])]
    public function createColumn(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $channelId = (int) $request->request->get('channelId');
        $channel = $this->entityManager->getRepository(Channel::class)->find($channelId);
        if (!$channel) {
            return new Response($this->translator->trans('Canal non trouvé.'), 404);
        }

        $name = trim($request->request->get('name', ''));
        if ($name === '') {
            return new Response($this->translator->trans('Le nom de la colonne est requis.'), 400);
        }

        $rawColor = (string) $request->request->get('color', '');
        $color = $rawColor !== '' ? $rawColor : null;

        try {
            $this->kanbanManager->createColumn($channel, $name, $color, null, $currentUser);
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

        $name = trim($request->request->get('name', ''));
        if ($name === '') {
            return new Response($this->translator->trans('Le nom de la colonne est requis.'), 400);
        }

        try {
            $this->kanbanManager->renameColumn($column, $name, $currentUser);
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

        $columnIds = $request->request->all('columnIds');
        if (!is_array($columnIds) || $columnIds === []) {
            return new Response($this->translator->trans('Paramètres invalides.'), 400);
        }

        try {
            $this->kanbanManager->reorderColumns(array_map('intval', $columnIds), $currentUser);
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

        $columnId = $request->request->get('columnId');
        $column = null;
        if ($columnId !== null && $columnId !== '' && $columnId !== 'null') {
            $column = $this->kanbanColumnRepository->find((int) $columnId);
        }

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

        $userId = $request->request->get('userId');
        $user = null;
        if ($userId !== null && $userId !== '' && $userId !== 'null') {
            $user = $this->userRepository->find((int) $userId);
        }

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

        $dueAtStr = $request->request->get('dueAt');
        $dueAt = null;
        if ($dueAtStr !== null && (string) $dueAtStr !== '') {
            $parsedDate = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $dueAtStr);
            $dueAt = $parsedDate !== false ? $parsedDate : null;
        }

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

        $rawPriority = (string) $request->request->get('priority', '');
        $priority = $rawPriority !== '' ? $rawPriority : null;

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

        $labelsReq = $request->request->get('labels');
        $labels = match (true) {
            is_array($labelsReq) => $labelsReq,
            is_string($labelsReq) && trim($labelsReq) !== '' => explode(',', $labelsReq),
            default => [],
        };
        $labels = array_values(array_filter(array_map('trim', $labels)));

        try {
            $this->kanbanManager->setLabels($message, $labels !== [] ? $labels : null, $currentUser);
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
            $this->kanbanManager->toggleCompletion($message, !$message->isCompleted(), $currentUser);
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
