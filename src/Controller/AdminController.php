<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ChannelExport;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Repository\AuditLogRepository;
use App\Repository\ChannelExportRepository;
use App\Repository\UserRepository;
use App\Service\AuditLoggerService;
use App\Service\CustomEmojiService;
use App\Service\FileUploadService;
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

    #[Route('/admin/exports', name: 'app_admin_exports')]
    public function exports(Request $request, ChannelExportRepository $exportRepository): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $exports = $exportRepository->findPaginated($page, self::PER_PAGE);
        $total = $exportRepository->countAll();
        $totalPages = (int) ceil($total / self::PER_PAGE);

        return $this->render('admin/exports.html.twig', [
            'exports' => $exports,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }

    #[Route('/admin/exports/{id}/download', name: 'app_admin_export_download')]
    public function downloadExport(
        ChannelExport $export,
        FileUploadService $fileUploadService,
        AuditLoggerService $auditLogger,
    ): Response {
        if (!$fileUploadService->exists($export->getFilePath())) {
            throw $this->createNotFoundException($this->translator->trans(
                'Le fichier d\'export n\'existe pas dans le stockage.',
            ));
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $auditLogger->log(AuditAction::EXPORT_DOWNLOAD, $currentUser, [
            'export_id' => $export->getId(),
            'file_name' => $export->getFileName(),
            'channel_name' => $export->getChannelName(),
        ]);

        $contentType = str_ends_with($export->getFileName(), '.tar') ? 'application/x-tar' : 'application/zip';

        return new StreamedResponse(
            function () use ($fileUploadService, $export) {
                $fileStream = $fileUploadService->readStream($export->getFilePath());
                if ($fileStream) {
                    fpassthru($fileStream);
                    fclose($fileStream);
                }
            },
            Response::HTTP_OK,
            [
                'Content-Type' => $contentType,
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT,
                    $export->getFileName(),
                ),
            ],
        );
    }

    #[Route('/admin/exports/{id}/delete', name: 'app_admin_export_delete', methods: ['POST'])]
    public function deleteExport(
        ChannelExport $export,
        EntityManagerInterface $entityManager,
        FileUploadService $fileUploadService,
        AuditLoggerService $auditLogger,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $auditLogger->log(AuditAction::EXPORT_DELETE, $currentUser, [
            'export_id' => $export->getId(),
            'file_name' => $export->getFileName(),
            'channel_name' => $export->getChannelName(),
        ]);

        try {
            $fileUploadService->delete($export->getFilePath());
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Failed to delete export file "%s": %s',
                $export->getFilePath(),
                $e->getMessage(),
            ));
        }

        $entityManager->remove($export);
        $entityManager->flush();

        $this->addFlash('success', $this->translator->trans('L\'export a été supprimé.'));

        return $this->redirectToRoute('app_admin_exports');
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

    #[Route('/admin/emojis', name: 'app_admin_emojis')]
    public function emojis(Request $request, CustomEmojiService $emojiService): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $q = trim($request->query->get('q', ''));

        $result = $emojiService->list($q);
        $totalPages = (int) ceil($result['total'] / self::PER_PAGE);
        $offset = ($page - 1) * self::PER_PAGE;
        $paginatedEmojis = array_slice($result['emojis'], $offset, self::PER_PAGE);

        return $this->render('admin/emojis.html.twig', [
            'emojis' => $paginatedEmojis,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $result['total'],
            'q' => $q,
        ]);
    }

    #[Route('/admin/emojis/edit', name: 'app_admin_emojis_edit', methods: ['POST'])]
    public function editEmoji(Request $request, CustomEmojiService $emojiService): Response
    {
        $code = $request->request->get('code', '');
        $tagsString = $request->request->get('tags', '');

        if ($code === '') {
            $this->addFlash('error', $this->translator->trans('Émoji invalide.'));
            return $this->redirectToRoute('app_admin_emojis');
        }

        try {
            $emojiService->saveTags($code, $tagsString);
            $this->addFlash('success', $this->translator->trans('Tags mis à jour pour l\'émoji %code%.', [
                '%code%' => $code,
            ]));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_emojis', $request->query->all());
    }

    #[Route('/admin/emojis/upload', name: 'app_admin_emojis_upload', methods: ['POST'])]
    public function uploadEmoji(Request $request, CustomEmojiService $emojiService): Response
    {
        $code = trim($request->request->get('code', ''));
        $file = $request->files->get('emoji_file');

        if ($code === '' || !$file) {
            $this->addFlash('error', $this->translator->trans('Le code et le fichier sont obligatoires.'));
            return $this->redirectToRoute('app_admin_emojis');
        }

        try {
            $emojiService->upload($code, $file, $request->request->get('tags', ''));
            $this->addFlash('success', $this->translator->trans('Émoji %code% ajouté avec succès.', [
                '%code%' => $code,
            ]));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $this->translator->trans('Erreur lors de l\'enregistrement de l\'émoji : %error%', [
                '%error%' => $e->getMessage(),
            ]));
        }

        return $this->redirectToRoute('app_admin_emojis');
    }

    #[Route('/admin/emojis/delete', name: 'app_admin_emojis_delete', methods: ['POST'])]
    public function deleteEmoji(Request $request, CustomEmojiService $emojiService): Response
    {
        $code = $request->request->get('code', '');

        try {
            $emojiService->delete($code);
            $this->addFlash('success', $this->translator->trans('L\'émoji %code% a été supprimé.', [
                '%code%' => $code,
            ]));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $this->translator->trans('Erreur lors de la suppression du fichier : %error%', [
                '%error%' => $e->getMessage(),
            ]));
        }

        return $this->redirectToRoute('app_admin_emojis');
    }

    #[Route('/admin/emojis/add-tag', name: 'app_admin_emojis_add_tag', methods: ['POST'])]
    public function addTag(Request $request, CustomEmojiService $emojiService): Response
    {
        $code = $request->request->get('code', '');
        $newTag = trim($request->request->get('tag', ''));

        try {
            $emojiService->addTag($code, $newTag);
            $this->addFlash('success', $this->translator->trans('Tag "%tag%" ajouté.', ['%tag%' => $newTag]));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_emojis', $request->query->all());
    }

    #[Route('/admin/emojis/remove-tag', name: 'app_admin_emojis_remove_tag', methods: ['POST'])]
    public function removeTag(Request $request, CustomEmojiService $emojiService): Response
    {
        $code = $request->request->get('code', '');
        $tagToRemove = trim($request->request->get('tag', ''));

        try {
            $emojiService->removeTag($code, $tagToRemove);
            $this->addFlash('success', $this->translator->trans('Tag "%tag%" retiré.', ['%tag%' => $tagToRemove]));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_emojis', $request->query->all());
    }
}
