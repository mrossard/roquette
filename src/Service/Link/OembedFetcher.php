<?php

declare(strict_types=1);

namespace App\Service\Link;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OembedFetcher
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UrlSafetyValidator $urlSafetyValidator,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function supports(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        return $host === 'open.spotify.com' || str_ends_with($host, '.spotify.com') || $host === 'spotify.link';
    }

    /**
     * @return array{type: string, url: string, title: string, description: string, image: string, siteName: string}|null
     */
    public function fetchPreview(string $url): ?array
    {
        if (!$this->supports($url)) {
            return null;
        }

        $oembedUrl = 'https://open.spotify.com/oembed?url=' . urlencode($url);
        if (!$this->urlSafetyValidator->isSafeUrl($oembedUrl)) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $oembedUrl, [
                'timeout' => 3.5,
                'max_redirects' => 2,
                'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)'],
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $data = $response->toArray(false);
            $rawTitle = $data['title'] ?? null;
            $title = is_string($rawTitle) ? trim($rawTitle) : '';
            if ($title === '') {
                return null;
            }

            $rawAuthor = $data['author_name'] ?? null;
            $author = is_string($rawAuthor) ? trim($rawAuthor) : '';
            $rawProvider = $data['provider_name'] ?? null;
            $provider = is_string($rawProvider) ? trim($rawProvider) : 'Spotify';
            $rawImage = $data['thumbnail_url'] ?? null;
            $image = is_string($rawImage) ? trim($rawImage) : '';

            return [
                'type' => 'og_preview',
                'url' => $url,
                'title' => $title,
                'description' => $author !== '' ? $author : $provider,
                'image' => $image,
                'siteName' => 'Spotify',
            ];
        } catch (\Throwable $e) {
            $this->logger->debug(sprintf('Failed to fetch oEmbed for "%s": %s', $url, $e->getMessage()));
            return null;
        }
    }
}
