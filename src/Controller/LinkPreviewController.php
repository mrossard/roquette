<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\LinkPreviewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class LinkPreviewController extends AbstractController
{
    public function __construct(
        private readonly LinkPreviewService $linkPreviewService,
    ) {}

    #[Route('/api/link-preview', name: 'app_api_link_preview', methods: ['GET'])]
    public function getPreview(Request $request): Response
    {
        $url = $request->query->get('url');
        if (!$url) {
            return new JsonResponse(['error' => 'URL parameter is missing'], 400);
        }

        $preview = $this->linkPreviewService->getPreview($url);
        if (!$preview) {
            // Return empty 200 response so HTMX replaces the placeholder with nothing, effectively removing it.
            return new Response('', 200);
        }

        return $this->render('dashboard/_link_preview.html.twig', [
            'url' => $preview['url'],
            'title' => $preview['title'],
            'description' => $preview['description'],
            'image' => $preview['image'],
            'siteName' => $preview['siteName'],
        ]);
    }
}
