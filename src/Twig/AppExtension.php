<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\MessageFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Extension Twig exposant les filtres `format_message` et `format_bytes`.
 *
 * La logique de formatage des messages est entièrement déléguée à
 * {@see MessageFormatter}, un service PHP pur testable unitairement.
 */
class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly MessageFormatter $formatter,
        private readonly TranslatorInterface $translator,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('format_message', [$this->formatter, 'format'], ['is_safe' => ['html']]),
            new TwigFilter('wrap_emojis', [$this->formatter, 'wrapUnicodeEmojis'], ['is_safe' => ['html']]),
            new TwigFilter('format_bytes', [$this, 'formatBytes']),
            new TwigFilter('reaction_tooltip', [$this, 'formatReactionTooltip']),
            new TwigFilter('extract_external_links', [$this, 'extractExternalLinks']),
        ];
    }

    public function extractExternalLinks(?string $content): array
    {
        if (!$content) {
            return [];
        }

        // Match http/https URLs
        preg_match_all('/https?:\/\/[^\s\)<>"]+/i', $content, $matches);
        if (empty($matches[0])) {
            return [];
        }

        return array_values(array_unique($matches[0]));
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
        $shortcode = \App\Service\EmojiMapping::getShortcode($emoji);
        $reactionName = $shortcode ? ':'.$shortcode.':' : $emoji;

        if (empty($usernames)) {
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
        $usersString = implode(', ', $usernames).' '.$and.' '.$lastUser;

        return $this->translator->trans('%users% ont réagi avec %reaction%', [
            '%users%' => $usersString,
            '%reaction%' => $reactionName,
        ]);
    }
}
