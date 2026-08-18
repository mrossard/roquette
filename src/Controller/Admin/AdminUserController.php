<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserManager;
use InvalidArgumentException;
use LogicException;
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
        private readonly TranslatorInterface $translator,
        private readonly UserManager $userManager,
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
    public function banUser(User $user): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $this->userManager->banUser($user, $currentUser);
            $this->addFlash('success', $this->translator->trans('L\'utilisateur "%username%" a été banni.', [
                '%username%' => $user->getUsername(),
            ]));
        } catch (LogicException|InvalidArgumentException $e) {
            $this->addFlash('error', $this->translator->trans($e->getMessage(), [
                '%username%' => $user->getUsername(),
            ]));
        }

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/admin/users/{id}/unban', name: 'app_admin_user_unban', methods: ['POST'])]
    public function unbanUser(User $user): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $this->userManager->unbanUser($user, $currentUser);
            $this->addFlash('success', $this->translator->trans('L\'utilisateur "%username%" a été réhabilité.', [
                '%username%' => $user->getUsername(),
            ]));
        } catch (LogicException|InvalidArgumentException $e) {
            $this->addFlash('error', $this->translator->trans($e->getMessage(), [
                '%username%' => $user->getUsername(),
            ]));
        }

        return $this->redirectToRoute('app_admin_users');
    }
}
