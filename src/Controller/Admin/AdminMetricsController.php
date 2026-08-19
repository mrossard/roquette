<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\WorkspaceMetricsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AdminMetricsController extends AbstractController
{
    public function __construct(
        private readonly WorkspaceMetricsService $metricsService,
    ) {}

    #[Route('/admin/metrics', name: 'app_admin_metrics', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $period = $request->query->getString('period', '30d');
        $workspaceIdParam = $request->query->get('workspace_id');
        $workspaceId = $workspaceIdParam !== null && $workspaceIdParam !== '' ? (int) $workspaceIdParam : null;

        $metrics = $this->metricsService->getMetrics($workspaceId, $period);

        if ($request->headers->get('HX-Request')) {
            return $this->render('admin/metrics/_content.html.twig', $metrics);
        }

        return $this->render('admin/metrics/index.html.twig', $metrics);
    }
}
