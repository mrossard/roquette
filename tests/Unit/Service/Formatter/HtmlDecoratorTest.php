<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Formatter;

use App\Service\Formatter\HtmlDecorator;
use PHPUnit\Framework\TestCase;

class HtmlDecoratorTest extends TestCase
{
    public function testDecoratesPreAndCodeBlocks(): void
    {
        $decorator = new HtmlDecorator();

        $html = '<pre><code>$x = 1;</code></pre>';
        $decorated = $decorator->decorate($html);

        self::assertStringContainsString('<pre class="message-code-block">', $decorated);
    }

    public function testDecoratesInlineCode(): void
    {
        $decorator = new HtmlDecorator();

        $html = '<p>Code: <code>$x = 1;</code></p>';
        $decorated = $decorator->decorate($html);

        self::assertStringContainsString('<code class="message-inline-code">$x = 1;</code>', $decorated);
    }

    public function testDecoratesExternalLinksWithTargetBlank(): void
    {
        $decorator = new HtmlDecorator();

        $html = '<p><a href="https://example.com">Site</a></p>';
        $decorated = $decorator->decorate($html);

        self::assertStringContainsString('<a href="https://example.com" target="_blank" rel="noopener noreferrer">Site</a>', $decorated);
    }

    public function testDecoratesInlineImagesForLightbox(): void
    {
        $decorator = new HtmlDecorator();

        $html = '<p><img src="https://example.com/photo.jpg" alt="Photo de vacances"></p>';
        $decorated = $decorator->decorate($html);

        self::assertStringContainsString('class="message-inline-image"', $decorated);
        self::assertStringContainsString('openLightbox(this.src, \'Photo de vacances\')', $decorated);
    }

    public function testDecoratesListsAndBlockquotes(): void
    {
        $decorator = new HtmlDecorator();

        $html = '<ul><li>Item</li></ul><ol><li>Item 1</li></ol><blockquote>Citation</blockquote>';
        $decorated = $decorator->decorate($html);

        self::assertStringContainsString('<ul class="message-list">', $decorated);
        self::assertStringContainsString('<ol class="message-list">', $decorated);
        self::assertStringContainsString('<blockquote class="message-quote">', $decorated);
    }
}
