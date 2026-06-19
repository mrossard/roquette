<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    #[Route('/api/health', name: 'app_health', methods: ['GET'])]
    public function health(EntityManagerInterface $entityManager): JsonResponse
    {
        $services = [];
        $hasError = false;

        // 1. Check Database connection
        try {
            $connection = $entityManager->getConnection();
            $connection->executeQuery('SELECT 1');
            $services['database'] = 'UP';
        } catch (\Exception $e) {
            $services['database'] = 'DOWN';
            $hasError = true;
        }

        $status = $hasError ? 'DOWN' : 'UP';
        $statusCode = $hasError ? 503 : 200;

        return new JsonResponse([
            'status' => $status,
            'timestamp' => time(),
            'services' => $services,
        ], $statusCode);
    }
}
