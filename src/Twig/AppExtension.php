<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\EmojiMapping;
use App\Service\MessageFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Extension Twig exposant les filtres de formatage et les fonctions déléguées aux runtimes.
 *
 * La logique lourde (requêtes Doctrine, sous-canaux, Mercure) est déléguée à
 * {@see AppExtensionRuntime} pour bénéficier du lazy loading Twig.
 */
class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly MessageFormatter $formatter,
        private readonly TranslatorInterface $translator,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_cached_link_preview', [AppExtensionRuntime::class, 'getCachedLinkPreview']),
            new TwigFunction('get_subchannel', [AppExtensionRuntime::class, 'getSubchannel']),
            new TwigFunction('get_user_mercure_topics', [AppExtensionRuntime::class, 'getUserMercureTopics']),
            new TwigFunction('get_user_channel_notifications_map', [AppExtensionRuntime::class, 'getUserChannelNotificationsMap']),
            new TwigFunction('get_pending_moderation_count', [AppExtensionRuntime::class, 'getPendingModerationCount']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('format_message', [$this->formatter, 'format'], ['is_safe' => ['html']]),
            new TwigFilter('wrap_emojis', [$this->formatter, 'wrapUnicodeEmojis'], ['is_safe' => ['html']]),
            new TwigFilter('format_bytes', [$this, 'formatBytes']),
            new TwigFilter('reaction_tooltip', [$this, 'formatReactionTooltip']),
            new TwigFilter('extract_external_links', [$this, 'extractExternalLinks']),
            new TwigFilter('is_image_url', [$this, 'isImageUrl']),
        ];
    }

    public function extractExternalLinks(?string $content): array
    {
        if (!$content) {
            return [];
        }

        // Strip Markdown image syntax ![alt](url) so that image URLs already
        // rendered by CommonMark are not extracted again as link previews.
        $content = preg_replace('/!\[.*?\]\(.*?\)/s', '', $content);

        // Match http/https URLs
        preg_match_all('/https?:\/\/[^\s\)<>"]+/i', $content, $matches);
        if ($matches[0] === []) {
            return [];
        }

        return array_values(array_unique($matches[0]));
    }

    /**
     * Vérifie si une URL pointe vers une image en se basant uniquement sur l'extension.
     * Utilisé dans les templates Twig pour éviter les placeholders HTMX pour les images directes.
     */
    public function isImageUrl(?string $url): bool
    {
        if (!$url) {
            return false;
        }
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'tiff', 'tif'];
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, $imageExtensions, true);
    }

    public function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= 1 << (10 * $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    public function formatReactionTooltip(array $usernames, string $emoji): string
    {
        $shortcode = EmojiMapping::getShortcode($emoji);
        $reactionName = $shortcode ? ':' . $shortcode . ':' : $emoji;

        if ($usernames === []) {
            return '';
        }

        $count = count($usernames);
        if ($count === 1) {
            return $this->translator->trans('%username% a réagi avec %reaction%', [
                '%username%' => $usernames[0],
                '%reaction%' => $reactionName,
            ]);
        }

        $lastUser = array_pop($usernames);
        $and = $this->translator->trans('et');
        $usersString = implode(', ', $usernames) . ' ' . $and . ' ' . $lastUser;

        return $this->translator->trans('%users% ont réagi avec %reaction%', [
            '%users%' => $usersString,
            '%reaction%' => $reactionName,
        ]);
    }
}
