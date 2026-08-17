<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Message;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Repository\MessageRepository;
use App\Service\AuditLoggerService;
use App\Service\MessageBroadcaster;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMIN')]
final class AdminModerationController extends AbstractController
{
    private const int PER_PAGE = 25;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {}

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
        MessageBroadcaster $messageBroadcaster,
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

        $messageBroadcaster->broadcastMessageUpdate($message);
        $messageBroadcaster->publishCurrentModerationCount();

        $this->addFlash('success', $this->translator->trans('Le message #%id% a été approuvé et rétabli.', [
            '%id%' => $message->getId(),
        ]));

        return $this->redirectToRoute('app_admin_moderation');
    }

    #[Route('/admin/moderation/{id}/delete', name: 'app_admin_moderation_delete', methods: ['POST'])]
    public function deleteModeratedMessage(
        Message $message,
        EntityManagerInterface $em,
        MessageBroadcaster $messageBroadcaster,
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

        $messageBroadcaster->broadcastMessageDeletion($channel, $messageId);
        $messageBroadcaster->publishCurrentModerationCount();

        $this->addFlash('success', $this->translator->trans('Le message #%id% a été supprimé.', [
            '%id%' => $messageId,
        ]));

        return $this->redirectToRoute('app_admin_moderation');
    }
}
