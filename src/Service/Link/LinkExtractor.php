<?php

declare(strict_types=1);

namespace App\Service\Link;

/**
 * Utility service for extracting external links from content and
 * detecting media/image URLs.
 */
class LinkExtractor
{
    public const array IMAGE_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'avif',
        'svg',
        'bmp',
        'tiff',
        'tif',
    ];

    /**
     * Extracts unique external HTTP/HTTPS URLs from content,
     * ignoring Markdown image syntax (![alt](url)).
     *
     * @return list<string>
     */
    public function extractExternalLinks(?string $content): array
    {
        if ($content === null || $content === '') {
            return [];
        }

        // Strip Markdown image syntax ![alt](url) so that image URLs already
        // rendered as inline images are not extracted again as link previews.
        $cleanContent = preg_replace('/!\[.*?\]\(.*?\)/s', '', $content);
        if ($cleanContent === null) {
            $cleanContent = $content;
        }

        // Match http/https URLs
        preg_match_all('/https?:\/\/[^\s\)<>"]+/i', $cleanContent, $matches);
        if ($matches[0] === []) {
            return [];
        }

        return array_values(array_unique($matches[0]));
    }

    /**
     * Checks if a given URL ends with a supported image extension.
     */
    public function isImageUrl(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }
}
