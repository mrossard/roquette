<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Formatter;

use App\Service\Formatter\EmojiProcessor;
use PHPUnit\Framework\TestCase;

class EmojiProcessorTest extends TestCase
{
    public function testProcessHtmlReplacesShortcodesAndUnicode(): void
    {
        $processor = new EmojiProcessor('http://example.com/emojis');

        $html = '<p>Hello :smile: and [:custom] and :o in text</p>';
        $processed = $processor->processHtml($html);

        static::assertStringContainsString('class="unicode-emoji"', $processed);
        static::assertStringContainsString('/emojis/custom.gif', $processed);
        static::assertStringContainsString('/icones/redface.gif', $processed);
    }

    public function testProcessHtmlIgnoresCodeBlocks(): void
    {
        $processor = new EmojiProcessor('http://example.com/emojis');

        $html = '<pre class="message-code-block"><code>[:custom] :smile:</code></pre>';
        $processed = $processor->processHtml($html);

        static::assertSame($html, $processed);
    }
}
