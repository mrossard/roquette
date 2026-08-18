<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Link\LinkPreviewDto;
use App\Service\Link\LinkExtractor;
use App\Service\Link\UrlSafetyValidator;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LinkPreviewService
{
    private readonly UrlSafetyValidator $urlSafetyValidator;
    private readonly LinkExtractor $linkExtractor;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly HttpClientInterface $httpClient,
        ?UrlSafetyValidator $urlSafetyValidator = null,
        ?LinkExtractor $linkExtractor = null,
    ) {
        $this->urlSafetyValidator = $urlSafetyValidator ?? new UrlSafetyValidator();
        $this->linkExtractor = $linkExtractor ?? new LinkExtractor();
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
            $item->expiresAfter(300);
            return null;
        }

        if ($this->linkExtractor->isImageUrl($url)) {
            $item->expiresAfter(86_400 * 7);
            return ['type' => 'direct_image', 'url' => $url];
        }

        try {
            return $this->downloadAndParsePreview($url, $item);
        } catch (\Exception) {
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
        foreach ($this->httpClient->stream($response, 1.5) as $chunk) {
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

        $metadata = $this->parseMetadata($url, $content);
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
                return null;
            }

            try {
                $response = $this->httpClient->request($method, $current, [
                    'timeout' => 1.5,
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
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * Extrait les métadonnées Open Graph / HTML standard depuis le document HTML.
     */
    private function parseMetadata(string $url, string $html): array
    {
        $crawler = new Crawler($html);

        // 1. Titre
        $titleNode = $crawler->filter('meta[property="og:title"], meta[name="twitter:title"]');
        $title = $titleNode->count() > 0 ? (string) $titleNode->first()->attr('content') : '';
        if ($title === '') {
            $titleFallbackNode = $crawler->filter('title');
            $title = $titleFallbackNode->count() > 0 ? $titleFallbackNode->first()->text() : '';
        }
        $title = html_entity_decode(trim(strip_tags($title)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 2. Description
        $descriptionNode = $crawler->filter(
            'meta[property="og:description"], meta[name="description"], meta[name="twitter:description"]',
        );
        $description = $descriptionNode->count() > 0 ? $descriptionNode->first()->attr('content') ?? '' : '';
        $description = html_entity_decode(trim(strip_tags($description)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 3. Image
        $imageNode = $crawler->filter('meta[property="og:image"], meta[name="twitter:image"]');
        $image = $imageNode->count() > 0 ? trim($imageNode->first()->attr('content') ?? '') : '';

        // Résoudre l'URL de l'image si elle est relative
        if ($image && !preg_match('/^https?:\/\//i', $image)) {
            $parsedUrl = parse_url($url);
            $base = ($parsedUrl['scheme'] ?? 'http') . '://' . ($parsedUrl['host'] ?? '');
            if (($parsedUrl['port'] ?? null) !== null) {
                $base .= ':' . $parsedUrl['port'];
            }
            $path = $parsedUrl['path'] ?? '';
            $dir = dirname($path);
            $dirPrefix = $dir === '/' ? '' : $dir;
            $image = str_starts_with($image, '/') ? $base . $image : $base . $dirPrefix . '/' . $image;
        }

        // 4. Nom du site
        $siteName = parse_url($url, PHP_URL_HOST);
        $siteNameNode = $crawler->filter('meta[property="og:site_name"]');
        if ($siteNameNode->count() > 0) {
            $siteName = html_entity_decode(
                trim(strip_tags($siteNameNode->first()->attr('content') ?? '')),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            );
        }

        return [
            'url' => $url,
            'title' => $title !== null && $title !== '' ? $title : $url,
            'description' => mb_strimwidth($description, 0, 200, '...'),
            'image' => $image,
            'siteName' => $siteName,
        ];
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
