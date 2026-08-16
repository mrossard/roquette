<?php

declare(strict_types=1);

namespace App\Service\Formatter;

final readonly class EmoticonProcessor
{
    private const EMOTICONS = [
        ':-)' => '🙂',
        ':)' => '🙂',
        ':-D' => '😀',
        ':D' => '😀',
        ';-)' => '😉',
        ';)' => '😉',
        ':-(' => '🙁',
        ':(' => '🙁',
        ':-P' => '😛',
        ':-p' => '😛',
        ':P' => '😛',
        ':p' => '😛',
        ':-O' => '😮',
        ':-o' => '😮',
        '&lt;3' => '❤️',
        '8)' => '😎',
        'B)' => '😎',
        'xD' => '😆',
        'XD' => '😆',
        ':-*' => '😘',
        ':*' => '😘',
        ':\'(' => '😢',
        ';(' => '😢',
    ];

    public function process(string $content): string
    {
        if (!preg_match('/[:;8BxX&<]/', $content)) {
            return $content;
        }

        $safeContent = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');

        $pattern = '/(?<=^|\s)(?:&lt;3|:(?:\'|&#039;)\(|:-\)|:-D|;-\)|:-\(|:-P|:-p|:-\*|:\)|:D|;\)|:\(|:P|:p|8\)|B\)|xD|XD|:\*|;\()(?=$|\s|[\.!?,])/';

        $safeContent = preg_replace_callback(
            $pattern,
            static function ($matches) {
                $key = $matches[0];
                if (str_contains($key, '&#039;')) {
                    $key = str_replace('&#039;', "'", $key);
                }

                return self::EMOTICONS[$key] ?? $matches[0];
            },
            $safeContent,
        );

        return htmlspecialchars_decode($safeContent, ENT_QUOTES);
    }
}
