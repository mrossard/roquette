<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\AuditAction;
use App\Repository\UserRepository;
use App\Service\AuditLoggerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMIN')]
final class AdminUserController extends AbstractController
{
    use AdminPaginationTrait;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/admin/users', name: 'app_admin_users')]
    public function users(Request $request, UserRepository $userRepository): Response
    {
        $page = $this->getPage($request);
        $users = $userRepository->findPaginated($page, self::ADMIN_PER_PAGE);
        $total = $userRepository->countAll();
        $totalPages = $this->calculateTotalPages($total);

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
}
