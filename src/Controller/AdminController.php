<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\ChannelExport;
use App\Repository\UserRepository;
use App\Repository\ChannelExportRepository;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMIN')]
final class AdminController extends AbstractController
{
    public function __construct(
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
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
    public function banUser(User $user, EntityManagerInterface $entityManager): Response
    {
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
    public function unbanUser(User $user, EntityManagerInterface $entityManager): Response
    {
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

    #[Route('/admin/exports', name: 'app_admin_exports')]
    public function exports(ChannelExportRepository $exportRepository): Response
    {
        $exports = $exportRepository->findBy([], ['createdAt' => 'DESC']);

        return $this->render('admin/exports.html.twig', [
            'exports' => $exports,
        ]);
    }

    #[Route('/admin/exports/{id}/download', name: 'app_admin_export_download')]
    public function downloadExport(
        ChannelExport $export,
        FileUploadService $fileUploadService,
    ): Response {
        if (!$fileUploadService->exists($export->getFilePath())) {
            throw $this->createNotFoundException($this->translator->trans('Le fichier d\'export n\'existe pas dans le stockage.'));
        }

        $fileStream = $fileUploadService->readStream($export->getFilePath());
        $fileContent = stream_get_contents($fileStream);
        if (is_resource($fileStream)) {
            fclose($fileStream);
        }

        $contentType = str_ends_with($export->getFileName(), '.tar') ? 'application/x-tar' : 'application/zip';

        $response = new Response($fileContent);
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set(
            'Content-Disposition',
            \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition(
                \Symfony\Component\HttpFoundation\HeaderUtils::DISPOSITION_ATTACHMENT,
                $export->getFileName()
            )
        );

        return $response;
    }

    #[Route('/admin/exports/{id}/delete', name: 'app_admin_export_delete', methods: ['POST'])]
    public function deleteExport(
        ChannelExport $export,
        EntityManagerInterface $entityManager,
        FileUploadService $fileUploadService,
    ): Response {
        try {
            $fileUploadService->delete($export->getFilePath());
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to delete export file "%s": %s', $export->getFilePath(), $e->getMessage()));
        }

        $entityManager->remove($export);
        $entityManager->flush();

        $this->addFlash('success', $this->translator->trans('L\'export a été supprimé.'));

        return $this->redirectToRoute('app_admin_exports');
    }
}
