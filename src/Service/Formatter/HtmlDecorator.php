<?php

declare(strict_types=1);

namespace App\Service\Formatter;

final readonly class HtmlDecorator
{
    public function decorate(string $html): string
    {
        // 1. Pre blocks
        $html = str_replace('<pre>', '<pre class="message-code-block">', $html);

        // 2. Inline code
        $html = preg_replace_callback(
            '/(?<!<pre class="message-code-block">)<code([^>]*)>/',
            static function ($matches) {
                $attrs = $matches[1];
                if (str_contains($attrs, 'class=')) {
                    return $matches[0];
                }
                return '<code class="message-inline-code"' . $attrs . '>';
            },
            $html,
        );

        // 3. Links
        $html = preg_replace_callback(
            '/<a\s+href="([^"]+)"([^>]*)>/',
            static function ($matches) {
                $url = $matches[1];
                $extra = $matches[2];
                if (str_contains($extra, 'target=')) {
                    return $matches[0];
                }

                // Internal/relative links stay in the current tab
                $isInternal =
                    str_starts_with($url, '/')
                    || str_starts_with($url, '#')
                    || !str_starts_with($url, 'http://')
                    && !str_starts_with($url, 'https://')
                    && !str_starts_with($url, '//');

                if ($isInternal) {
                    $boostAttr = !str_contains($extra, 'hx-boost=') ? ' hx-boost="false"' : '';
                    return '<a href="' . $url . '"' . $boostAttr . $extra . '>';
                }

                return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer"' . $extra . '>';
            },
            $html,
        );

        // 4. Inline images -> Lightbox
        $html = preg_replace_callback(
            '/<img\s+src="([^"]+)"\s+alt="([^"]*)"([^>]*)\/?>/',
            static function ($matches) {
                $url = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
                $alt = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
                return (
                    '<div class="message-inline-image-container"><img src="'
                    . $url
                    . '" alt="'
                    . $alt
                    . '" class="message-inline-image" onclick="openLightbox(this.src, \''
                    . addslashes($alt)
                    . '\')"></div>'
                );
            },
            $html,
        );

        // 5. Lists & Quotes
        $html = str_replace('<ul>', '<ul class="message-list">', $html);
        $html = str_replace('<ol>', '<ol class="message-list">', $html);
        return str_replace('<blockquote>', '<blockquote class="message-quote">', $html);
    }
}
