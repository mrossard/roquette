<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AdminAuditLogController extends AbstractController
{
    use AdminPaginationTrait;

    #[Route('/admin/audit-logs', name: 'app_admin_audit_logs')]
    public function auditLogs(Request $request, AuditLogRepository $auditLogRepository): Response
    {
        $page = $this->getPage($request);
        $logs = $auditLogRepository->findPaginated($page, self::ADMIN_PER_PAGE);
        $total = $auditLogRepository->countAll();
        $totalPages = $this->calculateTotalPages($total);

        return $this->render('admin/audit_logs.html.twig', [
            'logs' => $logs,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }
}
