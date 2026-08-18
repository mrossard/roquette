<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\EmojiMapping;
use App\Service\Link\LinkExtractor;
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
    private readonly LinkExtractor $linkExtractor;

    public function __construct(
        private readonly MessageFormatter $formatter,
        private readonly TranslatorInterface $translator,
        ?LinkExtractor $linkExtractor = null,
    ) {
        $this->linkExtractor = $linkExtractor ?? new LinkExtractor();
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_cached_link_preview', [AppExtensionRuntime::class, 'getCachedLinkPreview']),
            new TwigFunction('get_subchannel', [AppExtensionRuntime::class, 'getSubchannel']),
            new TwigFunction('get_user_mercure_topics', [AppExtensionRuntime::class, 'getUserMercureTopics']),
            new TwigFunction('get_user_channel_notifications_map', [
                AppExtensionRuntime::class,
                'getUserChannelNotificationsMap',
            ]),
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
            new TwigFilter('extract_external_links', [$this->linkExtractor, 'extractExternalLinks']),
            new TwigFilter('is_image_url', [$this->linkExtractor, 'isImageUrl']),
        ];
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
