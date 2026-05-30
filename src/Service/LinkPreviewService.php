<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class LinkPreviewService
{
    public function __construct(
        private readonly CacheInterface $cache
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
                $item->expiresAfter(0);
                return null;
            }

            try {
                $html = $this->fetchHtml($url);
                if (!$html) {
                    $item->expiresAfter(0);
                    return null;
                }

                $metadata = $this->parseMetadata($url, $html);
                if (empty($metadata['title'])) {
                    $item->expiresAfter(0);
                    return null;
                }

                $item->expiresAfter(3600); // 1 hour expiration for successful previews
                return $metadata;
            } catch (\Exception $e) {
                $item->expiresAfter(0);
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
        if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return false;
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = $parsed['host'];

        // Résoudre les adresses IP pour éviter SSRF sur le réseau privé/local
        $ips = gethostbynamel($host);
        if ($ips === false) {
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
        $context = stream_context_create([
            'http' => [
                'timeout' => 1.5,
                'user_agent' => 'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)',
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);

        // Lire un maximum de 1 Mo pour éviter de télécharger des fichiers volumineux tout en capturant les OG tags (YouTube/Spotify nécessitent parfois plus de 100 Ko)
        $html = @file_get_contents($url, false, $context, 0, 1_048_576);

        return $html ?: null;
    }

    /**
     * Extrait les métadonnées Open Graph / HTML standard depuis le document HTML.
     */
    private function parseMetadata(string $url, string $html): array
    {
        // 1. Titre
        $title = '';
        if (preg_match('/<meta[^>]*property=["\']og:title["\'][^>]*content=["\'](.*?)["\']/is', $html, $matches)
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
        if (preg_match('/<meta[^>]*property=["\']og:description["\'][^>]*content=["\'](.*?)["\']/is', $html, $matches)
            || preg_match('/<meta[^>]*content=["\'](.*?)["\'][^>]*property=["\']og:description["\']/is', $html, $matches)
            || preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\'](.*?)["\']/is', $html, $matches)
            || preg_match('/<meta[^>]*content=["\'](.*?)["\'][^>]*name=["\']description["\']/is', $html, $matches)
            || preg_match('/<meta[^>]*name=["\']twitter:description["\'][^>]*content=["\'](.*?)["\']/is', $html, $matches)
            || preg_match('/<meta[^>]*content=["\'](.*?)["\'][^>]*name=["\']twitter:description["\']/is', $html, $matches)
        ) {
            $description = $matches[1];
        }
        $description = html_entity_decode(trim(strip_tags($description)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 3. Image
        $image = '';
        if (preg_match('/<meta[^>]*property=["\']og:image["\'][^>]*content=["\'](.*?)["\']/is', $html, $matches)
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
            if (isset($parsedUrl['port'])) {
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
        if (preg_match('/<meta[^>]*property=["\']og:site_name["\'][^>]*content=["\'](.*?)["\']/is', $html, $matches)
            || preg_match('/<meta[^>]*content=["\'](.*?)["\'][^>]*property=["\']og:site_name["\']/is', $html, $matches)
        ) {
            $siteName = html_entity_decode(trim(strip_tags($matches[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            $siteName = parse_url($url, PHP_URL_HOST);
        }

        return [
            'url' => $url,
            'title' => $title ?: $url,
            'description' => mb_strimwidth($description, 0, 200, '...'),
            'image' => $image,
            'siteName' => $siteName,
        ];
    }
}
