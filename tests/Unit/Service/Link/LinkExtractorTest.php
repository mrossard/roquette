<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Link;

use App\Service\Link\LinkExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LinkExtractorTest extends TestCase
{
    private LinkExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new LinkExtractor();
    }

    #[Test]
    public function extractExternalLinksReturnsEmptyForNullOrEmpty(): void
    {
        static::assertSame([], $this->extractor->extractExternalLinks(null));
        static::assertSame([], $this->extractor->extractExternalLinks(''));
        static::assertSame([], $this->extractor->extractExternalLinks('   '));
    }

    #[Test]
    public function extractExternalLinksFindsMultipleHttpAndHttpsUrls(): void
    {
        $text = 'Check out https://symfony.com and http://php.net/manual/fr/index.php for more.';
        $links = $this->extractor->extractExternalLinks($text);

        static::assertSame(
            [
                'https://symfony.com',
                'http://php.net/manual/fr/index.php',
            ],
            $links,
        );
    }

    #[Test]
    public function extractExternalLinksDeduplicatesIdenticalUrls(): void
    {
        $text = 'Link https://example.com and repeat https://example.com here.';
        $links = $this->extractor->extractExternalLinks($text);

        static::assertSame(['https://example.com'], $links);
    }

    #[Test]
    public function extractExternalLinksIgnoresMarkdownImages(): void
    {
        $text = 'Here is an image ![My logo](https://example.com/logo.png) and a link [Doc](https://example.com/doc).';
        $links = $this->extractor->extractExternalLinks($text);

        static::assertSame(['https://example.com/doc'], $links);
        static::assertNotContains('https://example.com/logo.png', $links);
    }

    #[Test]
    public function isImageUrlRecognizesSupportedExtensions(): void
    {
        static::assertTrue($this->extractor->isImageUrl('https://example.com/test.jpg'));
        static::assertTrue($this->extractor->isImageUrl('https://example.com/test.JPEG'));
        static::assertTrue($this->extractor->isImageUrl('https://example.com/images/avatar.png'));
        static::assertTrue($this->extractor->isImageUrl('https://example.com/anim.gif'));
        static::assertTrue($this->extractor->isImageUrl('https://example.com/vector.svg'));
        static::assertTrue($this->extractor->isImageUrl('https://example.com/photo.webp'));
        static::assertTrue($this->extractor->isImageUrl('https://example.com/photo.avif'));
    }

    #[Test]
    public function isImageUrlRejectsNonImageExtensions(): void
    {
        static::assertFalse($this->extractor->isImageUrl('https://example.com/document.pdf'));
        static::assertFalse($this->extractor->isImageUrl('https://example.com/archive.zip'));
        static::assertFalse($this->extractor->isImageUrl('https://example.com/page.html'));
        static::assertFalse($this->extractor->isImageUrl('https://example.com/'));
        static::assertFalse($this->extractor->isImageUrl(null));
        static::assertFalse($this->extractor->isImageUrl(''));
    }
}
