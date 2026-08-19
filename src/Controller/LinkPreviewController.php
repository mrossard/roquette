<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\LinkPreviewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        $url = (string) $request->query->get('url');
        if ($url === '') {
            return new JsonResponse(['error' => 'URL parameter is missing'], 400);
        }

        $dto = $this->linkPreviewService->getPreviewDto($url);

        if ($dto === null) {
            $response = new Response('', 200);
            $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
            return $response;
        }

        $response = match (true) {
            $dto->isDirectImage() => $this->render('dashboard/_image_preview.html.twig', [
                'url' => $dto->url,
            ]),
            default => $this->render('dashboard/_link_preview.html.twig', [
                'url' => $dto->url,
                'title' => $dto->title,
                'description' => $dto->description,
                'image' => $dto->image,
                'siteName' => $dto->siteName,
            ]),
        };

        // Configure public HTTP caching (both for browser and Souin reverse proxy)
        $response->setPublic();
        $response->setMaxAge(86_400);
        $response->setSharedMaxAge(86_400);
        $response->headers->set('Symfony-Session-NoAutoCacheControl', 'true');

        return $response;
    }
}
