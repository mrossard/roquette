<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\MessageFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

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
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('format_message', [$this->formatter, 'format'], ['is_safe' => ['html']]),
            new TwigFilter('wrap_emojis', [$this->formatter, 'wrapUnicodeEmojis'], ['is_safe' => ['html']]),
            new TwigFilter('format_bytes', [$this, 'formatBytes']),
            new TwigFilter('reaction_tooltip', [$this, 'formatReactionTooltip']),
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
        $shortcode = \App\Service\EmojiMapping::getShortcode($emoji);
        $reactionName = $shortcode ? ':'.$shortcode.':' : $emoji;

        if (empty($usernames)) {
            return '';
        }

        $count = count($usernames);
        if ($count === 1) {
            return sprintf('%s a réagi avec %s', $usernames[0], $reactionName);
        }

        $lastUser = array_pop($usernames);
        $usersString = implode(', ', $usernames).' et '.$lastUser;

        return sprintf('%s ont réagi avec %s', $usersString, $reactionName);
    }
}
