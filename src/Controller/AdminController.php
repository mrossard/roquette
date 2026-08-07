<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ChannelExport;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\AuditAction;
use App\Repository\AuditLogRepository;
use App\Repository\ChannelExportRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use App\Service\AuditLoggerService;
use App\Service\CustomEmojiService;
use App\Service\FileUploadService;
use App\Service\MercurePublisher;
use App\Service\MessageRenderer;
use App\Service\WorkspaceManager;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMIN')]
final class AdminController extends AbstractController
{
    private const int PER_PAGE = 25;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/admin/users', name: 'app_admin_users')]
    public function users(Request $request, UserRepository $userRepository): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $users = $userRepository->findPaginated($page, self::PER_PAGE);
        $total = $userRepository->countAll();
        $totalPages = (int) ceil($total / self::PER_PAGE);

        return $this->render('admin/users.html.twig', [
            'users' => $users,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }

    #[Route('/admin/users/{id}/ban', name: 'app_admin_user_ban', methods: ['POST'])]
    public function banUser(
        User $user,
        EntityManagerInterface $entityManager,
        AuditLoggerService $auditLogger,
    ): Response {
        if ($user->isBanned()) {
            $this->addFlash('error', $this->translator->trans('L\'utilisateur "%username%" est déjà banni.', [
                '%username%' => $user->getUsername(),
            ]));

            return $this->redirectToRoute('app_admin_users');
        }

        if ($user->isAdmin()) {
            $this->addFlash('error', $this->translator->trans('Impossible de bannir un administrateur.'));

            return $this->redirectToRoute('app_admin_users');
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if ($user->getId() === $currentUser->getId()) {
            $this->addFlash('error', $this->translator->trans('Vous ne pouvez pas vous bannir vous-même.'));

            return $this->redirectToRoute('app_admin_users');
        }

        $user->setBannedAt(new \DateTimeImmutable());
        $user->setBannedReason('Banni par un administrateur');
        $entityManager->flush();

        $auditLogger->log(AuditAction::USER_BAN, $currentUser, [
            'banned_user_id' => $user->getId(),
            'username' => $user->getUsername(),
            'reason' => 'Banni par un administrateur',
        ]);

        $this->logger->info(sprintf(
            'User "%s" (ID: %d) has been banned by admin "%s" (ID: %d)',
            $user->getUsername(),
            $user->getId(),
            $currentUser->getUsername(),
            $currentUser->getId(),
        ));

        $this->addFlash('success', $this->translator->trans('L\'utilisateur "%username%" a été banni.', [
            '%username%' => $user->getUsername(),
        ]));

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/admin/users/{id}/unban', name: 'app_admin_user_unban', methods: ['POST'])]
    public function unbanUser(
        User $user,
        EntityManagerInterface $entityManager,
        AuditLoggerService $auditLogger,
    ): Response {
        if (!$user->isBanned()) {
            $this->addFlash('error', $this->translator->trans('L\'utilisateur "%username%" n\'est pas banni.', [
                '%username%' => $user->getUsername(),
            ]));

            return $this->redirectToRoute('app_admin_users');
        }

        $user->setBannedAt(null);
        $user->setBannedReason(null);
        $entityManager->flush();

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $auditLogger->log(AuditAction::USER_UNBAN, $currentUser, [
            'unbanned_user_id' => $user->getId(),
            'username' => $user->getUsername(),
        ]);

        $this->logger->info(sprintf(
            'User "%s" (ID: %d) has been unbanned by admin "%s" (ID: %d)',
            $user->getUsername(),
            $user->getId(),
            $currentUser->getUsername(),
            $currentUser->getId(),
        ));

        $this->addFlash('success', $this->translator->trans('L\'utilisateur "%username%" a été réhabilité.', [
            '%username%' => $user->getUsername(),
        ]));

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/admin/audit-logs', name: 'app_admin_audit_logs')]
    public function auditLogs(Request $request, AuditLogRepository $auditLogRepository): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $logs = $auditLogRepository->findPaginated($page, self::PER_PAGE);
        $total = $auditLogRepository->countAll();
        $totalPages = (int) ceil($total / self::PER_PAGE);

        return $this->render('admin/audit_logs.html.twig', [
            'logs' => $logs,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }

    #[Route('/admin/workspaces', name: 'app_admin_workspaces')]
    public function workspaces(WorkspaceRepository $workspaceRepo): Response
    {
        return $this->render('admin/workspaces.html.twig', [
            'workspaces' => $workspaceRepo->findBy([], ['name' => 'ASC']),
            'currentRoute' => 'app_admin_workspaces',
        ]);
    }

    #[Route('/admin/workspaces/delete/{id}', name: 'app_admin_workspace_delete', methods: ['POST'])]
    public function deleteWorkspace(
        Request $request,
        Workspace $workspace,
        WorkspaceManager $workspaceManager,
    ): Response {
        if (!$this->isCsrfTokenValid('delete-workspace-' . $workspace->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('Token CSRF invalide.'));

            return $this->redirectToRoute('app_admin_workspaces');
        }

        if ($workspace->isPublic()) {
            $this->addFlash('error', $this->translator->trans('Le workspace public ne peut pas être supprimé.'));

            return $this->redirectToRoute('app_admin_workspaces');
        }

        $workspaceManager->delete($workspace, $this->getUser());
        $this->addFlash('success', $this->translator->trans('Workspace supprimé avec succès.'));

        return $this->redirectToRoute('app_admin_workspaces');
    }

    #[Route('/admin/moderation', name: 'app_admin_moderation')]
    public function moderation(Request $request, MessageRepository $messageRepository): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $messages = $messageRepository->findModeratedPaginated($page, self::PER_PAGE);
        $total = $messageRepository->countPendingModeration();
        $totalPages = (int) ceil($total / self::PER_PAGE);

        return $this->render('admin/moderation.html.twig', [
            'messages' => $messages,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'currentRoute' => 'app_admin_moderation',
        ]);
    }

    #[Route('/admin/moderation/{id}/approve', name: 'app_admin_moderation_approve', methods: ['POST'])]
    public function approveMessage(
        Message $message,
        EntityManagerInterface $em,
        MercurePublisher $mercurePublisher,
        MessageRenderer $messageRenderer,
        AuditLoggerService $auditLogger,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if ($message->getOriginalContent() !== null) {
            $message->setContent($message->getOriginalContent());
        }

        $message->setModerationStatus('clean');
        $message->setModerationReason(null);
        $em->flush();

        $auditLogger->log(AuditAction::MESSAGE_MODERATED, $currentUser, [
            'action' => 'approved',
            'message_id' => $message->getId(),
            'author_username' => $message->getAuthor()?->getUsername(),
        ]);

        try {
            $channel = $message->getChannel();
            $html = $messageRenderer->renderFeedItem($message, ['oob' => true]);
            $mercurePublisher->publishToChannel($channel, $html, 'message_' . $channel->getSlug());
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to broadcast Mercure approval for message %d: %s', $message->getId(), $e->getMessage()));
        }

        $this->addFlash('success', $this->translator->trans('Le message #%id% a été approuvé et rétabli.', ['%id%' => $message->getId()]));

        return $this->redirectToRoute('app_admin_moderation');
    }

    #[Route('/admin/moderation/{id}/delete', name: 'app_admin_moderation_delete', methods: ['POST'])]
    public function deleteModeratedMessage(
        Message $message,
        EntityManagerInterface $em,
        MercurePublisher $mercurePublisher,
        AuditLoggerService $auditLogger,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $messageId = $message->getId();
        $channel = $message->getChannel();

        $auditLogger->log(AuditAction::MESSAGE_MODERATED, $currentUser, [
            'action' => 'deleted',
            'message_id' => $messageId,
            'author_username' => $message->getAuthor()?->getUsername(),
        ]);

        $em->remove($message);
        $em->flush();

        try {
            $deleteHtml = sprintf('<div id="feed-item-%d" hx-swap-oob="outerHTML"></div>', $messageId);
            $mercurePublisher->publishToChannel($channel, $deleteHtml, 'message_' . $channel->getSlug());
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to broadcast Mercure deletion for message %d: %s', $messageId, $e->getMessage()));
        }

        $this->addFlash('success', $this->translator->trans('Le message #%id% a été supprimé.', ['%id%' => $messageId]));

        return $this->redirectToRoute('app_admin_moderation');
    }
}

