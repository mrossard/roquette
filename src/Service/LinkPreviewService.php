<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Link\LinkPreviewDto;
use App\Service\Link\HtmlMetadataParser;
use App\Service\Link\LinkExtractor;
use App\Service\Link\OembedFetcher;
use App\Service\Link\UrlSafetyValidator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LinkPreviewService
{
    private readonly UrlSafetyValidator $urlSafetyValidator;
    private readonly LinkExtractor $linkExtractor;
    private readonly HtmlMetadataParser $htmlMetadataParser;
    private readonly OembedFetcher $oembedFetcher;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly HttpClientInterface $httpClient,
        ?UrlSafetyValidator $urlSafetyValidator = null,
        ?LinkExtractor $linkExtractor = null,
        ?HtmlMetadataParser $htmlMetadataParser = null,
        ?OembedFetcher $oembedFetcher = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->urlSafetyValidator = $urlSafetyValidator ?? new UrlSafetyValidator();
        $this->linkExtractor = $linkExtractor ?? new LinkExtractor();
        $this->htmlMetadataParser = $htmlMetadataParser ?? new HtmlMetadataParser();
        $this->oembedFetcher = $oembedFetcher ?? new OembedFetcher(
            $this->httpClient,
            $this->urlSafetyValidator,
            $this->logger,
        );
    }

    private const MAX_REDIRECTS = 3;

    /**
     * Vérifie si l'URL pointe directement vers une image (extension ou Content-Type).
     */
    public function isDirectImageUrl(string $url): bool
    {
        if ($this->linkExtractor->isImageUrl($url)) {
            return true;
        }

        // Pas d'extension image : on fait un HEAD pour vérifier le Content-Type
        $response = $this->fetch($url, 'HEAD');
        if ($response === null) {
            return false;
        }

        try {
            $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
            return str_starts_with($contentType, 'image/');
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Retourne un tableau avec 'type' => 'direct_image' ou les métadonnées OG, ou null.
     * Utilisé par le contrôleur pour choisir le template de rendu.
     */
    public function getPreviewWithType(string $url): ?array
    {
        return $this->getPreview($url);
    }

    /**
     * Retourne un DTO LinkPreviewDto typé, ou null en cas d'échec.
     */
    public function getPreviewDto(string $url): ?LinkPreviewDto
    {
        $preview = $this->getPreview($url);
        if ($preview === null) {
            return null;
        }

        if (($preview['type'] ?? '') === 'direct_image') {
            return LinkPreviewDto::directImage((string) ($preview['url'] ?? $url));
        }

        return LinkPreviewDto::ogPreview(
            url: (string) ($preview['url'] ?? $url),
            title: $preview['title'] ?? null,
            description: $preview['description'] ?? null,
            image: $preview['image'] ?? null,
            siteName: $preview['siteName'] ?? null,
        );
    }

    /**
     * Obtient l'aperçu du lien (Open Graph) ou null s'il échoue.
     */
    public function getPreview(string $url): ?array
    {
        $cleanUrl = trim($url);
        $cacheKey = 'link_preview_' . md5($cleanUrl);

        $value = $this->cache->get($cacheKey, fn(ItemInterface $item) => $this->fetchPreviewData($cleanUrl, $item));

        if (is_array($value) && !array_key_exists('type', $value)) {
            $value['type'] = 'og_preview';
        }

        return $value;
    }

    private function fetchPreviewData(string $url, ItemInterface $item): ?array
    {
        if (!$this->urlSafetyValidator->isSafeUrl($url)) {
            $this->logger->debug(sprintf('Link preview skipped (unsafe URL): "%s"', $url));
            $item->expiresAfter(300);
            return null;
        }

        if ($this->linkExtractor->isImageUrl($url)) {
            $item->expiresAfter(86_400 * 7);
            return ['type' => 'direct_image', 'url' => $url];
        }

        $oembedPreview = $this->oembedFetcher->fetchPreview($url);
        if ($oembedPreview !== null) {
            $item->expiresAfter(86_400 * 7);
            return $oembedPreview;
        }

        try {
            return $this->downloadAndParsePreview($url, $item);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Link preview parse failed for "%s": %s', $url, $e->getMessage()));
            $item->expiresAfter(300);
            return null;
        }
    }

    private function downloadAndParsePreview(string $url, ItemInterface $item): ?array
    {
        $response = $this->fetch($url, 'GET');
        if ($response === null) {
            $item->expiresAfter(300);
            return null;
        }

        $headers = $response->getHeaders(false);
        $contentType = $headers['content-type'][0] ?? '';

        if (str_starts_with($contentType, 'image/')) {
            $response->cancel();
            $item->expiresAfter(86_400 * 7);
            return ['type' => 'direct_image', 'url' => $url];
        }

        if (!str_contains($contentType, 'text/html') && !str_contains($contentType, 'application/xhtml+xml')) {
            $response->cancel();
            $item->expiresAfter(300);
            return null;
        }

        $content = '';
        foreach ($this->httpClient->stream($response, 3.5) as $chunk) {
            $content .= $chunk->getContent();
            if (strlen($content) >= 1_048_576) {
                $response->cancel();
                break;
            }
        }

        if ($content === '') {
            $item->expiresAfter(300);
            return null;
        }

        $metadata = $this->htmlMetadataParser->parse($url, $content);
        $titleVal = $metadata['title'] ?? null;
        if ($titleVal === null || trim((string) $titleVal) === '') {
            $item->expiresAfter(300);
            return null;
        }

        $item->expiresAfter(86_400 * 7);
        return array_merge(['type' => 'og_preview'], $metadata);
    }

    /**
     * Envoie une requête HTTP en suivant manuellement les redirects, en
     * re-validant chaque hôte (protection SSRF). Retourne la réponse finale
     * (non 3xx) ou null si une URL est invalide / non sûre / trop de redirects.
     */
    private function fetch(string $url, string $method = 'GET'): ?\Symfony\Contracts\HttpClient\ResponseInterface
    {
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (!$this->urlSafetyValidator->isSafeUrl($current)) {
                $this->logger->debug(sprintf('URL not safe for preview fetch: "%s"', $current));
                return null;
            }

            try {
                $response = $this->httpClient->request($method, $current, [
                    'timeout' => 3.5,
                    'max_redirects' => 0,
                    'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)'],
                ]);

                $status = $response->getStatusCode();

                if ($status >= 300 && $status < 400) {
                    $location = $response->getHeaders(false)['location'][0] ?? null;
                    if ($location === null) {
                        return null;
                    }

                    $next = $this->urlSafetyValidator->resolveUrl($current, $location);
                    if ($next === null) {
                        return null;
                    }

                    $current = $next;
                    continue;
                }

                return $response;
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    'HTTP request failed for link preview "%s": %s',
                    $current,
                    $e->getMessage(),
                ));
                return null;
            }
        }

        return null;
    }

    /**
     * Gets the cached preview without making any HTTP request.
     * Returns:
     * - ['status' => 'success', 'preview' => array] if cached and valid
     * - ['status' => 'negative'] if cached as invalid/null
     * - null if not in cache (cache miss)
     */
    public function getCachedPreview(string $url): ?array
    {
        $url = trim($url);
        $cacheKey = 'link_preview_' . md5($url);

        if (method_exists($this->cache, 'getItem')) {
            try {
                $item = $this->cache->getItem($cacheKey);
                if ($item->isHit()) {
                    $value = $item->get();
                    if ($value === null) {
                        return ['status' => 'negative'];
                    }

                    // Backward compatibility check for older cached entries
                    if (is_array($value) && !array_key_exists('type', $value)) {
                        $value['type'] = 'og_preview';
                    }

                    return ['status' => 'success', 'preview' => $value];
                }
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
