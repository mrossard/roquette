<?php

namespace App\Controller;

use App\Service\LinkPreviewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class LinkPreviewController extends AbstractController
{
    public function __construct(
        private readonly LinkPreviewService $linkPreviewService
    ) {}

    #[Route('/api/link-preview', name: 'app_api_link_preview', methods: ['GET'])]
    public function getPreview(Request $request): JsonResponse
    {
        $url = $request->query->get('url');
        if (!$url) {
            return new JsonResponse(['error' => 'URL parameter is missing'], 400);
        }

        $preview = $this->linkPreviewService->getPreview($url);
        if (!$preview) {
            return new JsonResponse(['error' => 'Could not fetch metadata for this URL or URL is unsafe'], 400);
        }

        return new JsonResponse($preview);
    }
}
