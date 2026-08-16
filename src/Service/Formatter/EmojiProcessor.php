<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Service\EmojiMapping;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class EmojiProcessor
{
    private ?array $reverseEmojiMapping = null;

    public function __construct(
        #[Autowire('%env(EMOJI_BASE_URL)%')]
        private readonly string $emojiBaseUrl = '',
    ) {}

    public function processHtml(string $html): string
    {
        $parts = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }

        $inCodeOrPre = 0;
        foreach ($parts as &$part) {
            if ($part === '') {
                continue;
            }
            if ($part[0] === '<') {
                $tagName = strtolower(preg_replace('/^<\/?([a-z0-9]+).*/is', '$1', $part));
                if ($tagName === 'code' || $tagName === 'pre') {
                    $inCodeOrPre = str_starts_with($part, '</')
                        ? max(0, $inCodeOrPre - 1)
                        : $inCodeOrPre + 1;
                }
                continue;
            }

            if ($inCodeOrPre === 0) {
                $part = $this->replaceShortcodes($part);
                $part = $this->wrapUnicodeEmojis($part);
                $part = $this->replaceCustomEmojis($part);
                $part = $this->replaceRedfaceEmoji($part);
            }
        }
        unset($part);

        return implode('', $parts);
    }

    public function replaceShortcodes(string $text): string
    {
        if (!str_contains($text, ':')) {
            return $text;
        }

        return preg_replace_callback(
            '/:([a-zA-Z0-9_\-\+]+):/',
            static function ($matches) {
                $shortcode = $matches[1];
                if (\array_key_exists($shortcode, EmojiMapping::MAPPING)) {
                    return EmojiMapping::MAPPING[$shortcode];
                }

                return $matches[0];
            },
            $text,
        );
    }

    public function wrapUnicodeEmojis(string $text): string
    {
        $pattern = '/(?:[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{1F1E6}-\x{1F1FF}\x{1F3FB}-\x{1F3FF}]\x{FE0F}?|\x{200D})+/u';
        return preg_replace_callback(
            $pattern,
            function ($matches) {
                $emoji = $matches[0];
                $shortcode = $this->getShortcodeForUnicodeEmoji($emoji);
                if ($shortcode) {
                    return (
                        '<span class="unicode-emoji" title=":'
                        . htmlspecialchars($shortcode, ENT_QUOTES, 'UTF-8')
                        . ':">'
                        . $emoji
                        . '</span>'
                    );
                }
                return '<span class="unicode-emoji">' . $emoji . '</span>';
            },
            $text,
        );
    }

    private function getShortcodeForUnicodeEmoji(string $emoji): ?string
    {
        if ($this->reverseEmojiMapping === null) {
            $this->reverseEmojiMapping = [];
            foreach (EmojiMapping::MAPPING as $code => $unicode) {
                if (\array_key_exists($unicode, $this->reverseEmojiMapping)) {
                    continue;
                }

                $this->reverseEmojiMapping[$unicode] = $code;
            }
        }
        return $this->reverseEmojiMapping[$emoji] ?? null;
    }

    public function replaceCustomEmojis(string $text): string
    {
        if ($this->emojiBaseUrl === '' || !str_contains($text, '[:')) {
            return $text;
        }

        return preg_replace_callback(
            '/\[:([a-zA-Z0-9_\-\+: ]+)\]/',
            static function ($matches) {
                $code = $matches[1];

                $pos = strrpos($code, ':');
                $webPath = $pos !== false
                    ? '/emojis/' . rawurlencode(substr($code, $pos + 1)) . '/' . rawurlencode(basename(substr($code, 0, $pos) . '.gif'))
                    : '/emojis/' . rawurlencode(basename($code . '.gif'));

                $title = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

                return (
                    '<img src="'
                    . htmlspecialchars($webPath, ENT_QUOTES, 'UTF-8')
                    . '" alt="[:'
                    . $title
                    . ']" title="[:'
                    . $title
                    . ']" class="message-emoji" style="vertical-align: middle;" />'
                );
            },
            $text,
        );
    }

    public function replaceRedfaceEmoji(string $text): string
    {
        if ($this->emojiBaseUrl === '') {
            return $text;
        }

        $parsed = parse_url($this->emojiBaseUrl);
        $origin = $parsed['scheme'] . '://' . $parsed['host'];
        if (\array_key_exists('port', $parsed) && $parsed['port'] !== null) {
            $origin .= ':' . $parsed['port'];
        }
        $url = $origin . '/icones/redface.gif';

        $pattern = '/(?<=^|\s):-?o(?=$|\s|[\.!?,])/i';
        return preg_replace(
            $pattern,
            '<img src="'
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            . '" alt=":o" title=":o" class="message-emoji" style="vertical-align: middle;" />',
            $text,
        );
    }
}
