<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ChannelExport;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Repository\ChannelExportRepository;
use App\Service\AuditLoggerService;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMIN')]
final class AdminExportController extends AbstractController
{
    private const int PER_PAGE = 25;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {}

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
        \App\Service\FileStreamResponseFactory $fileResponseFactory,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $auditLogger->log(AuditAction::EXPORT_DOWNLOAD, $currentUser, [
            'export_id' => $export->getId(),
            'file_name' => $export->getFileName(),
            'channel_name' => $export->getChannelName(),
        ]);

        return $fileResponseFactory->createExportDownloadResponse($export, $fileUploadService);
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
}
