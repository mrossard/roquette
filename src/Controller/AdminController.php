<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AdminController extends AbstractController
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    #[Route('/admin/users', name: 'app_admin_users')]
    public function users(UserRepository $userRepository): Response
    {
        $users = $userRepository->getAllSortedByDisplayName($withRobot = false);

        //$users = $userRepository->findBy([], ['displayName' => 'ASC']);

        return $this->render('admin/users.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/admin/users/{id}/ban', name: 'app_admin_user_ban', methods: ['POST'])]
    public function banUser(
        User $user,
        EntityManagerInterface $entityManager,
    ): Response {
        if ($user->isBanned()) {
            $this->addFlash(
                'error',
                sprintf(
                    'L\'utilisateur "%s" est déjà banni.',
                    $user->getUsername(),
                ),
            );

            return $this->redirectToRoute('app_admin_users');
        }

        if ($user->isAdmin()) {
            $this->addFlash('error', 'Impossible de bannir un administrateur.');

            return $this->redirectToRoute('app_admin_users');
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if ($user->getId() === $currentUser->getId()) {
            $this->addFlash('error', 'Vous ne pouvez pas vous bannir vous-même.');

            return $this->redirectToRoute('app_admin_users');
        }

        $user->setBannedAt(new \DateTimeImmutable());
        $user->setBannedReason('Banni par un administrateur');
        $entityManager->flush();

        $this->logger->info(
            sprintf(
                'User "%s" (ID: %d) has been banned by admin "%s" (ID: %d)',
                $user->getUsername(),
                $user->getId(),
                $currentUser->getUsername(),
                $currentUser->getId(),
            ),
        );

        $this->addFlash(
            'success',
            sprintf(
                'L\'utilisateur "%s" a été banni.',
                $user->getUsername(),
            ),
        );

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/admin/users/{id}/unban', name: 'app_admin_user_unban', methods: ['POST'])]
    public function unbanUser(
        User $user,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$user->isBanned()) {
            $this->addFlash(
                'error',
                sprintf(
                    'L\'utilisateur "%s" n\'est pas banni.',
                    $user->getUsername(),
                ),
            );

            return $this->redirectToRoute('app_admin_users');
        }

        $user->setBannedAt(null);
        $user->setBannedReason(null);
        $entityManager->flush();

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $this->logger->info(
            sprintf(
                'User "%s" (ID: %d) has been unbanned by admin "%s" (ID: %d)',
                $user->getUsername(),
                $user->getId(),
                $currentUser->getUsername(),
                $currentUser->getId(),
            ),
        );

        $this->addFlash(
            'success',
            sprintf(
                'L\'utilisateur "%s" a été réhabilité.',
                $user->getUsername(),
            ),
        );

        return $this->redirectToRoute('app_admin_users');
    }
}
