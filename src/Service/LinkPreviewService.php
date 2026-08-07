<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LinkPreviewService
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly HttpClientInterface $httpClient,
    ) {}

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'tiff', 'tif'];

    private const MAX_REDIRECTS = 3;

    /**
     * IPv4 ranges not covered by FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
     * but that must never be fetched (SSRF protection).
     */
    private const EXTRA_BLOCKED_RANGES = [
        ['100.64.0.0', '100.127.255.255'], // CGNAT
        ['198.18.0.0', '198.19.255.255'],  // Benchmarking
        ['192.0.0.0', '192.0.0.255'],      // IETF protocol assignments
        ['0.0.0.0', '0.255.255.255'],      // "This network"
    ];

    /**
     * Vérifie si l'URL pointe directement vers une image (extension ou Content-Type).
     */
    public function isDirectImageUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
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
        if (!$this->isSafeUrl($url)) {
            $item->expiresAfter(300);
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
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
            if (!$this->isSafeUrl($current)) {
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

                    $next = $this->resolveUrl($current, $location);
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
     * Résout une URL de redirection, éventuellement relative, par rapport à l'URL courante.
     */
    private function resolveUrl(string $base, string $location): ?string
    {
        $location = trim($location);
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parsed = parse_url($base);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? null;
        if ($host === null) {
            return null;
        }

        $port = \array_key_exists('port', $parsed) && $parsed['port'] !== null ? ':' . $parsed['port'] : '';

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }

        $path = $parsed['path'] ?? '/';
        $dir = str_replace('\\', '/', dirname($path));
        if ($dir === '.' || $dir === '/') {
            $dir = '';
        }

        return $scheme . '://' . $host . $port . $dir . '/' . $location;
    }

    /**
     * Vérifie si l'URL est valide et sûre (évite SSRF).
     */
    private function isSafeUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parsed = parse_url($url);
        if (!$parsed || ($parsed['scheme'] ?? null) === null || ($parsed['host'] ?? null) === null) {
            return false;
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = $parsed['host'];
        $cleanHost = $host;
        if (str_starts_with($cleanHost, '[') && str_ends_with($cleanHost, ']')) {
            $cleanHost = substr($cleanHost, 1, -1);
        }

        $ips = [];
        if (filter_var($cleanHost, FILTER_VALIDATE_IP)) {
            $ips[] = $cleanHost;
        } else {
            // Résolution DNS des enregistrements IPv4 (A)
            $ipv4Records = dns_get_record($cleanHost, DNS_A);
            if (is_array($ipv4Records)) {
                foreach ($ipv4Records as $record) {
                    if (($record['ip'] ?? null) === null) {
                        continue;
                    }

                    $ips[] = $record['ip'];
                }
            }
            // Résolution DNS des enregistrements IPv6 (AAAA)
            $ipv6Records = dns_get_record($cleanHost, DNS_AAAA);
            if (is_array($ipv6Records)) {
                foreach ($ipv6Records as $record) {
                    if (($record['ipv6'] ?? null) === null) {
                        continue;
                    }

                    $ips[] = $record['ipv6'];
                }
            }

            // Repli vers gethostbynamel si aucune IP n'a été résolue (ex. fichiers hosts locaux)
            if ($ips === []) {
                $fallbackIps = gethostbynamel($cleanHost);
                if (is_array($fallbackIps)) {
                    $ips = array_merge($ips, $fallbackIps);
                }
            }
        }

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Retourne true si l'IP est publique (ni privée, ni réservée, ni dans les
     * plages bloquées supplémentaires).
     */
    private function isPublicIp(string $ip): bool
    {
        // IPv4-mapped IPv6 (ex. ::ffff:127.0.0.1) — vérifie la partie IPv4.
        $lower = strtolower($ip);
        if (str_starts_with($lower, '::ffff:')) {
            $v4 = substr($lower, 7);
            if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return filter_var(
                    $v4,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
                ) !== false && !$this->isInExtraBlockedRange($v4);
            }

            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return !$this->isInExtraBlockedRange($ip);
    }

    private function isInExtraBlockedRange(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }

        foreach (self::EXTRA_BLOCKED_RANGES as [$start, $end]) {
            if ($long >= ip2long($start) && $long <= ip2long($end)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extrait les métadonnées Open Graph / HTML standard depuis le document HTML.
     */
    private function parseMetadata(string $url, string $html): array
    {
        $crawler = new Crawler($html);

        // 1. Titre
        $titleNode = $crawler->filter('meta[property="og:title"], meta[name="twitter:title"]');
        if ($titleNode->count() > 0) {
            $title = $titleNode->first()->attr('content') ?? '';
        } else {
            $titleNode = $crawler->filter('title');
            $title = $titleNode->count() > 0 ? $titleNode->first()->text() : '';
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
            if (str_starts_with($image, '/')) {
                $image = $base . $image;
            } else {
                $path = $parsedUrl['path'] ?? '';
                $dir = dirname($path);
                $image = $base . ($dir === '/' ? '' : $dir) . '/' . $image;
            }
        }

        // 4. Nom du site
        $siteNameNode = $crawler->filter('meta[property="og:site_name"]');
        if ($siteNameNode->count() > 0) {
            $siteName = html_entity_decode(
                trim(strip_tags($siteNameNode->first()->attr('content') ?? '')),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            );
        } else {
            $siteName = parse_url($url, PHP_URL_HOST);
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
