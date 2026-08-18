<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Link;

use App\Service\Link\HtmlMetadataParser;
use PHPUnit\Framework\TestCase;

class HtmlMetadataParserTest extends TestCase
{
    private HtmlMetadataParser $parser;

    protected function setUp(): void
    {
        $this->parser = new HtmlMetadataParser();
    }

    public function testParseOpenGraphMetadata(): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta property="og:title" content="OG Title &amp; More" />
    <meta property="og:description" content="OG description of the page." />
    <meta property="og:image" content="https://example.com/images/og.jpg" />
    <meta property="og:site_name" content="Example Site" />
</head>
<body></body>
</html>
HTML;

        $metadata = $this->parser->parse('https://example.com/article/1', $html);

        static::assertSame('https://example.com/article/1', $metadata['url']);
        static::assertSame('OG Title & More', $metadata['title']);
        static::assertSame('OG description of the page.', $metadata['description']);
        static::assertSame('https://example.com/images/og.jpg', $metadata['image']);
        static::assertSame('Example Site', $metadata['siteName']);
    }

    public function testParseTwitterCardsFallback(): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta name="twitter:title" content="Twitter Title" />
    <meta name="twitter:description" content="Twitter description." />
    <meta name="twitter:image" content="https://example.com/twitter.png" />
</head>
<body></body>
</html>
HTML;

        $metadata = $this->parser->parse('https://example.com/post', $html);

        static::assertSame('Twitter Title', $metadata['title']);
        static::assertSame('Twitter description.', $metadata['description']);
        static::assertSame('https://example.com/twitter.png', $metadata['image']);
        static::assertSame('example.com', $metadata['siteName']);
    }

    public function testParseStandardHtmlFallback(): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Standard Page Title</title>
    <meta name="description" content="Standard meta description." />
</head>
<body></body>
</html>
HTML;

        $metadata = $this->parser->parse('https://blog.example.org:8080/page', $html);

        static::assertSame('Standard Page Title', $metadata['title']);
        static::assertSame('Standard meta description.', $metadata['description']);
        static::assertSame('', $metadata['image']);
        static::assertSame('blog.example.org', $metadata['siteName']);
    }

    public function testParseResolvesRelativeImageUrls(): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta property="og:title" content="Relative Image Test" />
    <meta property="og:image" content="/assets/hero.png" />
</head>
<body></body>
</html>
HTML;

        $metadata = $this->parser->parse('https://example.com:8443/nested/path/page.html', $html);
        static::assertSame('https://example.com:8443/assets/hero.png', $metadata['image']);

        $htmlRelative = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta property="og:title" content="Relative Image Test 2" />
    <meta property="og:image" content="cover.png" />
</head>
<body></body>
</html>
HTML;

        $metadataRelative = $this->parser->parse('https://example.com/nested/path/page.html', $htmlRelative);
        static::assertSame('https://example.com/nested/path/cover.png', $metadataRelative['image']);
    }

    public function testParseTruncatesLongDescription(): void
    {
        $longText = str_repeat('Long description word ', 20);
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta property="og:title" content="Truncate test" />
    <meta property="og:description" content="{$longText}" />
</head>
<body></body>
</html>
HTML;

        $metadata = $this->parser->parse('https://example.com', $html);
        static::assertLessThanOrEqual(203, mb_strlen($metadata['description']));
        static::assertStringEndsWith('...', $metadata['description']);
    }
}
