<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\LinkPreviewService;
use Twig\Extension\RuntimeExtensionInterface;

class AppExtensionRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly LinkPreviewService $linkPreviewService,
    ) {}

    public function getCachedLinkPreview(string $url): ?array
    {
        return $this->linkPreviewService->getCachedPreview($url);
    }
}
