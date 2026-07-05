<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\KanbanColumnRepository;
use App\Repository\MessageRepository;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
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
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly KanbanManager $kanbanManager,
        private readonly KanbanColumnRepository $kanbanColumnRepository,
        private readonly MessageRepository $messageRepository,
        private readonly ChannelRepository $channelRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/channels/{slug}/kanban', name: 'app_channel_kanban', methods: ['GET'])]
    public function kanbanBoard(
        string $slug,
        Request $request,
        \App\Repository\InvitationRepository $invitationRepository,
        \App\Repository\WorkspaceRepository $workspaceRepository,
        \App\Service\ChannelManager $channelManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $channel = $this->channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel || !$channel->isTodoList()) {
            throw $this->createNotFoundException($this->translator->trans('Canal non trouvé ou non configuré en todo.'));
        }

        $columns = $this->kanbanColumnRepository->findByChannelWithMessages($channel);

        // Messages not assigned to any column (untriaged)
        $untriagedMessages = $this->messageRepository->createQueryBuilder('m')
            ->leftJoin('m.author', 'a')
            ->addSelect('a')
            ->leftJoin('m.assignedTo', 'at')
            ->addSelect('at')
            ->leftJoin('m.reactions', 'r')
            ->addSelect('r')
            ->where('m.channel = :channel')
            ->andWhere('m.kanbanColumn IS NULL')
            ->setParameter('channel', $channel)
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();

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
        $channels = $this->channelRepository->findAllForUser($currentUser);
        $workspaces = $workspaceRepository->findAllForUser($currentUser);
        $ucrRepo = $this->entityManager->getRepository(\App\Entity\UserChannelRead::class);
        $unreadCounts = $ucrRepo->getUnreadCounts($currentUser);
        $pendingInvitations = $invitationRepository->findPendingForUser($currentUser);

        $activeRead = $ucrRepo->findOneBy(['user' => $currentUser, 'channel' => $channel]);
        $notificationsEnabled = $activeRead ? $activeRead->isNotificationsEnabled() : $channel->isDm();

        $subChannelsByParent = $channelManager->buildSubChannelsByParent($channels);

        return $this->render('dashboard/index.html.twig', [
            'channels' => $channels,
            'activeChannel' => $channel,
            'messages' => [],
            'topic_url' => '',
            'unreadCounts' => $unreadCounts,
            'firstUnreadMessageId' => null,
            'usersToInvite' => [],
            'pendingInvitations' => $pendingInvitations,
            'isMember' => true,
            'notificationsEnabled' => $notificationsEnabled,
            'typingUsers' => [],
            'subChannelsByParent' => $subChannelsByParent,
            'replyCounts' => [],
            'subchannelByParentMessageId' => [],
            'lastMessages' => [],
            'workspaces' => $workspaces,
            'workspaceUnreadCounts' => [],
            'kanbanView' => true,
            'kanbanColumns' => $columns,
            'untriagedMessages' => $untriagedMessages,
            'kanbanMembers' => $members,
        ]);
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

        $color = $request->request->get('color') ?: null;

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
    public function moveMessage(int $id, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $message = $this->messageRepository->find($id);
        if (!$message) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

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

        return $this->render('dashboard/_kanban_card.html.twig', [
            'message' => $message,
            'channel' => $message->getChannel(),
        ]);
    }

    #[Route('/messages/{id}/assign', name: 'app_kanban_assign', methods: ['POST'])]
    public function assignMessage(int $id, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $message = $this->messageRepository->find($id);
        if (!$message) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

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

        return $this->render('dashboard/_kanban_card.html.twig', [
            'message' => $message,
            'channel' => $message->getChannel(),
        ]);
    }

    #[Route('/messages/{id}/due-date', name: 'app_kanban_due_date', methods: ['POST'])]
    public function setDueDate(int $id, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $message = $this->messageRepository->find($id);
        if (!$message) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        $dueAtStr = $request->request->get('dueAt');
        $dueAt = null;
        if ($dueAtStr !== null && $dueAtStr !== '') {
            $dueAt = \DateTimeImmutable::createFromFormat('Y-m-d', $dueAtStr) ?: null;
        }

        try {
            $this->kanbanManager->setDueDate($message, $dueAt, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return $this->render('dashboard/_kanban_card.html.twig', [
            'message' => $message,
            'channel' => $message->getChannel(),
        ]);
    }

    #[Route('/messages/{id}/priority', name: 'app_kanban_priority', methods: ['POST'])]
    public function setPriority(int $id, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $message = $this->messageRepository->find($id);
        if (!$message) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        $priority = $request->request->get('priority') ?: null;

        try {
            $this->kanbanManager->setPriority($message, $priority, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return $this->render('dashboard/_kanban_card.html.twig', [
            'message' => $message,
            'channel' => $message->getChannel(),
        ]);
    }

    #[Route('/messages/{id}/labels', name: 'app_kanban_labels', methods: ['POST'])]
    public function setLabels(int $id, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $message = $this->messageRepository->find($id);
        if (!$message) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        $labelsReq = $request->request->get('labels');
        if (is_array($labelsReq)) {
            $labels = $labelsReq;
        } elseif (is_string($labelsReq) && trim($labelsReq) !== '') {
            $labels = explode(',', $labelsReq);
        } else {
            $labels = [];
        }
        $labels = array_values(array_filter(array_map('trim', $labels)));

        try {
            $this->kanbanManager->setLabels($message, $labels ?: null, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return $this->render('dashboard/_kanban_card.html.twig', [
            'message' => $message,
            'channel' => $message->getChannel(),
        ]);
    }

    #[Route('/messages/{id}/kanban-complete', name: 'app_kanban_toggle_complete', methods: ['POST'])]
    public function toggleComplete(int $id): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $message = $this->messageRepository->find($id);
        if (!$message) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        $completed = !$message->isCompleted();

        // Sync reaction ✅ to match completion state
        $reactionRepo = $this->entityManager->getRepository(\App\Entity\Reaction::class);
        $existingCheck = $reactionRepo->findOneBy([
            'message' => $message,
            'user' => $currentUser,
            'emoji' => '✅',
        ]);

        if ($completed && !$existingCheck) {
            $reaction = new \App\Entity\Reaction();
            $reaction->setMessage($message);
            $reaction->setUser($currentUser);
            $reaction->setEmoji('✅');
            $this->entityManager->persist($reaction);
        } elseif (!$completed && $existingCheck) {
            $this->entityManager->remove($existingCheck);
        }

        $message->setIsCompleted($completed);
        $this->entityManager->flush();

        $this->kanbanManager->toggleCompletion($message, $completed, $currentUser);

        return $this->render('dashboard/_kanban_card.html.twig', [
            'message' => $message,
            'channel' => $message->getChannel(),
        ]);
    }
}
