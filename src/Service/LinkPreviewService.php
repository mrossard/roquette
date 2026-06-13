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
        try {
            $response = $this->httpClient->request('HEAD', $url, [
                'timeout' => 1.5,
                'max_redirects' => 3,
                'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)'],
            ]);
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
        $url = trim($url);
        if (!$this->isSafeUrl($url)) {
            return null;
        }

        if ($this->isDirectImageUrl($url)) {
            return ['type' => 'direct_image', 'url' => $url];
        }

        $preview = $this->getPreview($url);
        if ($preview === null) {
            return null;
        }

        return array_merge(['type' => 'og_preview'], $preview);
    }

    /**
     * Obtient l'aperçu du lien (Open Graph) ou null s'il échoue.
     */
    public function getPreview(string $url): ?array
    {
        // Nettoyer l'URL
        $url = trim($url);

        // Clé de cache basée sur l'URL hachée
        $cacheKey = 'link_preview_' . md5($url);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($url) {
            if (!$this->isSafeUrl($url)) {
                // Pour éviter d'attaquer l'infra en boucle, on garde en cache (négatif) les URLs invalides/non sécurisées pendant 5 min
                $item->expiresAfter(300);
                return null;
            }

            try {
                $html = $this->fetchHtml($url);
                if (!$html) {
                    $item->expiresAfter(300); // Cache négatif : 5 minutes en cas d'échec de récupération
                    return null;
                }

                $metadata = $this->parseMetadata($url, $html);
                $titleVal = $metadata['title'] ?? null;
                if ($titleVal === null || trim((string) $titleVal) === '') {
                    $item->expiresAfter(300); // Cache négatif : 5 minutes en cas de métadonnées invalides
                    return null;
                }

                $item->expiresAfter(3600); // 1 hour expiration for successful previews
                return $metadata;
            } catch (\Exception $e) {
                $item->expiresAfter(300); // Cache négatif : 5 minutes en cas d'exception/erreur réseau
                // Silencieusement ignorer les erreurs de requêtes externes
                return null;
            }
        });
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
            $ipv4Records = @dns_get_record($cleanHost, DNS_A);
            if (is_array($ipv4Records)) {
                foreach ($ipv4Records as $record) {
                    if (($record['ip'] ?? null) === null) {
                        continue;
                    }

                    $ips[] = $record['ip'];
                }
            }
            // Résolution DNS des enregistrements IPv6 (AAAA)
            $ipv6Records = @dns_get_record($cleanHost, DNS_AAAA);
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
                $fallbackIps = @gethostbynamel($cleanHost);
                if (is_array($fallbackIps)) {
                    $ips = array_merge($ips, $fallbackIps);
                }
            }
        }

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Récupère le début du contenu HTML de l'URL avec un timeout strict.
     */
    private function fetchHtml(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 1.5,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)',
                ],
                'max_redirects' => 3,
            ]);

            $content = '';
            foreach ($this->httpClient->stream($response, 1.5) as $chunk) {
                $content .= $chunk->getContent();
                if (strlen($content) >= 1_048_576) {
                    $response->cancel();
                    break;
                }
            }

            return $content !== '' ? $content : null;
        } catch (\Exception) {
            return null;
        }
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
        $descriptionNode = $crawler->filter('meta[property="og:description"], meta[name="description"], meta[name="twitter:description"]');
        $description = $descriptionNode->count() > 0 ? ($descriptionNode->first()->attr('content') ?? '') : '';
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
            $siteName = html_entity_decode(trim(strip_tags($siteNameNode->first()->attr('content') ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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

                    return ['status' => 'success', 'preview' => $value];
                }
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
