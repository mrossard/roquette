<?php

declare(strict_types=1);

namespace App\Service\Link;

use Symfony\Component\DomCrawler\Crawler;

class HtmlMetadataParser
{
    /**
     * @return array{url: string, title: string, description: string, image: string, siteName: ?string}
     */
    public function parse(string $url, string $html): array
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
        if ($image !== '' && !preg_match('/^https?:\/\//i', $image)) {
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
            'title' => $title !== '' ? $title : $url,
            'description' => mb_strimwidth($description, 0, 200, '...'),
            'image' => $image,
            'siteName' => is_string($siteName) ? $siteName : null,
        ];
    }
}
