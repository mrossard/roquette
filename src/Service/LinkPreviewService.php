<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LinkPreviewService
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly HttpClientInterface $httpClient,
    ) {}

    /**
     * Obthient l'aperçu du lien (Open Graph) ou null s'il échoue.
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
        // 1. Titre
        $title = '';
        if (
            preg_match('/<meta[^>]*property=["\']og:title["\'][^>]*content=["\'](.*?)["\']/is', $html, $matches)
            || preg_match('/<meta[^>]*content=["\'](.*?)["\'][^>]*property=["\']og:title["\']/is', $html, $matches)
            || preg_match('/<meta[^>]*name=["\']twitter:title["\'][^>]*content=["\'](.*?)["\']/is', $html, $matches)
            || preg_match('/<meta[^>]*content=["\'](.*?)["\'][^>]*name=["\']twitter:title["\']/is', $html, $matches)
        ) {
            $title = $matches[1];
        } elseif (preg_match('/<title>(.*?)<\/title>/is', $html, $matches)) {
            $title = $matches[1];
        }
        $title = html_entity_decode(trim(strip_tags($title)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 2. Description
        $description = '';
        if (
            preg_match('/<meta[^>]*property=["\']og:description["\'][^>]*content=["\'](.*?)["\']/is', $html, $matches)
            || preg_match(
                '/<meta[^>]*content=["\'](.*?)["\'][^>]*property=["\']og:description["\']/is',
                $html,
                $matches,
            )
            || preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\'](.*?)["\']/is', $html, $matches)
            || preg_match('/<meta[^>]*content=["\'](.*?)["\'][^>]*name=["\']description["\']/is', $html, $matches)
            || preg_match(
                '/<meta[^>]*name=["\']twitter:description["\'][^>]*content=["\'](.*?)["\']/is',
                $html,
                $matches,
            )
            || preg_match(
                '/<meta[^>]*content=["\'](.*?)["\'][^>]*name=["\']twitter:description["\']/is',
                $html,
                $matches,
            )
        ) {
            $description = $matches[1];
        }
        $description = html_entity_decode(trim(strip_tags($description)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 3. Image
        $image = '';
        if (
            preg_match('/<meta[^>]*property=["\']og:image["\'][^>]*content=["\'](.*?)["\']/is', $html, $matches)
            || preg_match('/<meta[^>]*content=["\'](.*?)["\'][^>]*property=["\']og:image["\']/is', $html, $matches)
            || preg_match('/<meta[^>]*name=["\']twitter:image["\'][^>]*content=["\'](.*?)["\']/is', $html, $matches)
            || preg_match('/<meta[^>]*content=["\'](.*?)["\'][^>]*name=["\']twitter:image["\']/is', $html, $matches)
        ) {
            $image = trim($matches[1]);
        }

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
        $siteName = '';
        if (
            preg_match('/<meta[^>]*property=["\']og:site_name["\'][^>]*content=["\'](.*?)["\']/is', $html, $matches)
            || preg_match('/<meta[^>]*content=["\'](.*?)["\'][^>]*property=["\']og:site_name["\']/is', $html, $matches)
        ) {
            $siteName = html_entity_decode(trim(strip_tags($matches[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
