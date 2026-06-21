<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ChannelExport;
use App\Entity\CustomEmoji;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Repository\AuditLogRepository;
use App\Repository\ChannelExportRepository;
use App\Repository\UserRepository;
use App\Service\AuditLoggerService;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
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
    public function emojis(
        Request $request,
        FilesystemOperator $defaultStorage,
        EntityManagerInterface $entityManager,
        CacheInterface $cache,
    ): Response {
        $page = max(1, $request->query->getInt('page', 1));
        $q = trim($request->query->get('q', ''));

        // Load all custom emojis from DB
        $dbEmojis = $entityManager->getRepository(CustomEmoji::class)->findAll();
        $emojiTagsMap = [];
        foreach ($dbEmojis as $dbEmoji) {
            $emojiTagsMap[$dbEmoji->getCode()] = $dbEmoji->getTags();
        }

        $matchingEmojis = [];
        try {
            $files = $cache->get('emojis_filesystem_list', function () use ($defaultStorage) {
                $list = [];
                try {
                    $contents = $defaultStorage->listContents('emojis', true);
                    foreach ($contents as $attributes) {
                        if ($attributes->isFile()) {
                            $list[] = [
                                'path' => $attributes->path(),
                                'size' => $attributes->fileSize(),
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    // Ignore
                }
                return $list;
            });

            foreach ($files as $file) {
                $path = $file['path'];
                $relativePath = substr($path, \strlen('emojis/'));
                if (!str_ends_with($relativePath, '.gif')) {
                    continue;
                }
                if ($file['size'] === 0) {
                    continue;
                }
                $noExt = substr($relativePath, 0, -4);
                $parts = explode('/', $noExt);
                $filePart = (string) array_pop($parts);
                if (\count($parts) === 0) {
                    $code = $filePart;
                    $filename = $filePart . '.gif';
                } else {
                    $dir = implode('/', $parts);
                    $code = $filePart . ':' . $dir;
                    $filename = $dir . '/' . $filePart . '.gif';
                }

                $tags = $emojiTagsMap[$code] ?? [];

                // Filter by search query if any (matches code or any tag)
                if ($q !== '') {
                    $match = str_contains(mb_strtolower($code), mb_strtolower($q));
                    if (!$match) {
                        foreach ($tags as $tag) {
                            if (str_contains(mb_strtolower($tag), mb_strtolower($q))) {
                                $match = true;
                                break;
                            }
                        }
                    }
                    if (!$match) {
                        continue;
                    }
                }

                $matchingEmojis[] = [
                    'code' => $code,
                    'filename' => $filename,
                    'tags' => $tags,
                ];
            }
        } catch (\Exception $e) {
            // Ignore
        }

        usort($matchingEmojis, static fn($a, $b) => strcmp($a['code'], $b['code']));

        $total = count($matchingEmojis);
        $totalPages = (int) ceil($total / self::PER_PAGE);
        $offset = ($page - 1) * self::PER_PAGE;
        $paginatedEmojis = array_slice($matchingEmojis, $offset, self::PER_PAGE);

        return $this->render('admin/emojis.html.twig', [
            'emojis' => $paginatedEmojis,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'q' => $q,
        ]);
    }

    #[Route('/admin/emojis/edit', name: 'app_admin_emojis_edit', methods: ['POST'])]
    public function editEmoji(Request $request, EntityManagerInterface $entityManager): Response
    {
        $code = $request->request->get('code', '');
        $tagsString = $request->request->get('tags', '');

        if ($code === '') {
            $this->addFlash('error', $this->translator->trans('Émoji invalide.'));
            return $this->redirectToRoute('app_admin_emojis');
        }

        // Clean up tags
        $tags = array_map('trim', explode(',', $tagsString));

        $customEmoji = $entityManager->getRepository(CustomEmoji::class)->findOneBy(['code' => $code]);
        if (!$customEmoji) {
            $customEmoji = new CustomEmoji();
            $customEmoji->setCode($code);
            $customEmoji->setFilename($this->deduceEmojiFilename($code));
        }

        $customEmoji->setTags($tags);

        $entityManager->persist($customEmoji);
        $entityManager->flush();

        $this->addFlash('success', $this->translator->trans('Tags mis à jour pour l\'émoji %code%.', [
            '%code%' => $code,
        ]));

        return $this->redirectToRoute('app_admin_emojis', $request->query->all());
    }

    #[Route('/admin/emojis/upload', name: 'app_admin_emojis_upload', methods: ['POST'])]
    public function uploadEmoji(
        Request $request,
        FilesystemOperator $defaultStorage,
        EntityManagerInterface $entityManager,
        CacheInterface $cache,
    ): Response {
        $code = trim($request->request->get('code', ''));
        $file = $request->files->get('emoji_file');

        if ($code === '' || !$file) {
            $this->addFlash('error', $this->translator->trans('Le code et le fichier sont obligatoires.'));
            return $this->redirectToRoute('app_admin_emojis');
        }

        // Ensure file is a GIF
        if (
            $file->getMimeType() !== 'image/gif'
            || !str_ends_with(strtolower($file->getClientOriginalName()), '.gif')
        ) {
            $this->addFlash(
                'error',
                $this->translator->trans('Seuls les fichiers GIF sont supportés pour les émojis personnalisés.'),
            );
            return $this->redirectToRoute('app_admin_emojis');
        }

        // Sanitize code
        $sanitizedCode = preg_replace('/[^a-zA-Z0-9_\-\+:]/', '', $code);
        if ($sanitizedCode !== $code) {
            $this->addFlash(
                'error',
                $this->translator->trans(
                    'Le code contient des caractères invalides. Utilisez des lettres, chiffres, tirets, underscores ou deux-points.',
                ),
            );
            return $this->redirectToRoute('app_admin_emojis');
        }

        // Deduce storage path
        $pos = strrpos($sanitizedCode, ':');
        if ($pos !== false) {
            $name = substr($sanitizedCode, 0, $pos);
            $dir = substr($sanitizedCode, $pos + 1);
            $filename = $dir . '/' . basename($name . '.gif');
        } else {
            $filename = basename($sanitizedCode . '.gif');
        }

        $storagePath = 'emojis/' . $filename;

        try {
            $content = file_get_contents($file->getPathname());
            $defaultStorage->write($storagePath, $content);
            $cache->delete('emojis_filesystem_list');

            $tagsString = $request->request->get('tags', '');
            $tags = array_map('trim', explode(',', $tagsString));

            $customEmoji = $entityManager->getRepository(CustomEmoji::class)->findOneBy(['code' => $sanitizedCode]);
            if (!$customEmoji) {
                $customEmoji = new CustomEmoji();
                $customEmoji->setCode($sanitizedCode);
                $customEmoji->setFilename($filename);
            }
            $customEmoji->setTags($tags);

            $entityManager->persist($customEmoji);
            $entityManager->flush();

            $this->addFlash('success', $this->translator->trans('Émoji %code% ajouté avec succès.', [
                '%code%' => $sanitizedCode,
            ]));
        } catch (\Exception $e) {
            $this->addFlash('error', $this->translator->trans('Erreur lors de l\'enregistrement de l\'émoji : %error%', [
                '%error%' => $e->getMessage(),
            ]));
        }

        return $this->redirectToRoute('app_admin_emojis');
    }

    #[Route('/admin/emojis/delete', name: 'app_admin_emojis_delete', methods: ['POST'])]
    public function deleteEmoji(
        Request $request,
        FilesystemOperator $defaultStorage,
        EntityManagerInterface $entityManager,
        CacheInterface $cache,
    ): Response {
        $code = $request->request->get('code', '');

        if ($code === '') {
            $this->addFlash('error', $this->translator->trans('Émoji invalide.'));
            return $this->redirectToRoute('app_admin_emojis');
        }

        $customEmoji = $entityManager->getRepository(CustomEmoji::class)->findOneBy(['code' => $code]);
        if ($customEmoji) {
            $filename = $customEmoji->getFilename();
            $entityManager->remove($customEmoji);
            $entityManager->flush();
        } else {
            $filename = $this->deduceEmojiFilename($code);
        }

        $storagePath = 'emojis/' . $filename;
        try {
            if ($defaultStorage->has($storagePath)) {
                $defaultStorage->delete($storagePath);
            }
            $cache->delete('emojis_filesystem_list');
            $this->addFlash('success', $this->translator->trans('L\'émoji %code% a été supprimé.', [
                '%code%' => $code,
            ]));
        } catch (\Exception $e) {
            $this->addFlash('error', $this->translator->trans('Erreur lors de la suppression du fichier : %error%', [
                '%error%' => $e->getMessage(),
            ]));
        }

        return $this->redirectToRoute('app_admin_emojis');
    }

    #[Route('/admin/emojis/add-tag', name: 'app_admin_emojis_add_tag', methods: ['POST'])]
    public function addTag(Request $request, EntityManagerInterface $entityManager): Response
    {
        $code = $request->request->get('code', '');
        $newTag = trim($request->request->get('tag', ''));

        if ($code === '' || $newTag === '') {
            $this->addFlash('error', $this->translator->trans('Émoji ou tag invalide.'));
            return $this->redirectToRoute('app_admin_emojis', $request->query->all());
        }

        $customEmoji = $entityManager->getRepository(CustomEmoji::class)->findOneBy(['code' => $code]);
        if (!$customEmoji) {
            $customEmoji = new CustomEmoji();
            $customEmoji->setCode($code);
            $customEmoji->setFilename($this->deduceEmojiFilename($code));
        }

        $tags = $customEmoji->getTags();
        if (!in_array($newTag, $tags, true)) {
            $tags[] = $newTag;
            $customEmoji->setTags($tags);
            $entityManager->persist($customEmoji);
            $entityManager->flush();
            $this->addFlash('success', $this->translator->trans('Tag "%tag%" ajouté.', ['%tag%' => $newTag]));
        }

        return $this->redirectToRoute('app_admin_emojis', $request->query->all());
    }

    #[Route('/admin/emojis/remove-tag', name: 'app_admin_emojis_remove_tag', methods: ['POST'])]
    public function removeTag(Request $request, EntityManagerInterface $entityManager): Response
    {
        $code = $request->request->get('code', '');
        $tagToRemove = trim($request->request->get('tag', ''));

        if ($code === '' || $tagToRemove === '') {
            $this->addFlash('error', $this->translator->trans('Émoji ou tag invalide.'));
            return $this->redirectToRoute('app_admin_emojis', $request->query->all());
        }

        $customEmoji = $entityManager->getRepository(CustomEmoji::class)->findOneBy(['code' => $code]);
        if ($customEmoji) {
            $tags = $customEmoji->getTags();
            $key = array_search($tagToRemove, $tags, true);
            if ($key !== false) {
                unset($tags[$key]);
                $customEmoji->setTags(array_values($tags));
                $entityManager->persist($customEmoji);
                $entityManager->flush();
                $this->addFlash('success', $this->translator->trans('Tag "%tag%" retiré.', ['%tag%' => $tagToRemove]));
            }
        }

        return $this->redirectToRoute('app_admin_emojis', $request->query->all());
    }

    private function deduceEmojiFilename(string $code): string
    {
        $pos = strrpos($code, ':');
        if ($pos !== false) {
            $name = substr($code, 0, $pos);
            $dir = substr($code, $pos + 1);
            return $dir . '/' . basename($name . '.gif');
        }

        return basename($code . '.gif');
    }
}
