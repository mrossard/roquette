<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Service\Link\LinkExtractor;
use App\Service\MessageFormatter;
use App\Twig\AppExtension;
use App\Twig\AppExtensionRuntime;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class AppExtensionTest extends TestCase
{
    private AppExtension $extension;
    private LinkExtractor $linkExtractor;

    protected function setUp(): void
    {
        $formatter = $this->createMock(MessageFormatter::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(static function (string $id, array $parameters = []) {
                if ($id === 'et') {
                    return 'et';
                }

                return strtr($id, $parameters);
            });

        $this->linkExtractor = new LinkExtractor();
        $this->extension = new AppExtension($formatter, $translator, $this->linkExtractor);
    }

    #[Test]
    public function getFunctionsRegistersRuntimeCallables(): void
    {
        $functions = $this->extension->getFunctions();
        $names = array_map(static fn($f) => $f->getName(), $functions);

        static::assertContains('get_cached_link_preview', $names);
        static::assertContains('get_subchannel', $names);
        static::assertContains('get_user_mercure_topics', $names);
        static::assertContains('get_user_channel_notifications_map', $names);
        static::assertContains('get_pending_moderation_count', $names);

        foreach ($functions as $function) {
            $callable = $function->getCallable();
            static::assertIsArray($callable);
            static::assertSame(AppExtensionRuntime::class, $callable[0]);
        }
    }

    #[Test]
    public function getFiltersRegistersExpectedFilters(): void
    {
        $filters = $this->extension->getFilters();
        $names = array_map(static fn($f) => $f->getName(), $filters);

        static::assertContains('format_message', $names);
        static::assertContains('wrap_emojis', $names);
        static::assertContains('format_bytes', $names);
        static::assertContains('reaction_tooltip', $names);
        static::assertContains('extract_external_links', $names);
        static::assertContains('is_image_url', $names);
    }

    public function testFormatReactionTooltipWithSingleUser(): void
    {
        $result = $this->extension->formatReactionTooltip(['Alice'], '😀');
        static::assertSame('Alice a réagi avec :grinning:', $result);
    }

    public function testFormatReactionTooltipWithMultipleUsers(): void
    {
        $result = $this->extension->formatReactionTooltip(['Alice', 'Bob'], '😀');
        static::assertSame('Alice et Bob ont réagi avec :grinning:', $result);
    }

    public function testFormatReactionTooltipWithThreeUsers(): void
    {
        $result = $this->extension->formatReactionTooltip(['Alice', 'Bob', 'Charlie'], '😀');
        static::assertSame('Alice, Bob et Charlie ont réagi avec :grinning:', $result);
    }

    public function testFormatReactionTooltipWithUnknownEmoji(): void
    {
        $result = $this->extension->formatReactionTooltip(['Alice'], '🚀');
        static::assertSame('Alice a réagi avec :rocket:', $result);
    }

    public function testFormatBytes(): void
    {
        static::assertSame('500 B', $this->extension->formatBytes(500));
        static::assertSame('1 KB', $this->extension->formatBytes(1024));
        static::assertSame('1.5 MB', $this->extension->formatBytes((int) (1.5 * 1024 * 1024)));
    }

    public function testExtractExternalLinksFilter(): void
    {
        $filters = array_values(array_filter(
            $this->extension->getFilters(),
            static fn($f) => $f->getName() === 'extract_external_links',
        ));
        static::assertNotEmpty($filters);
        $callable = $filters[0]->getCallable();
        static::assertIsCallable($callable);

        $content = 'Check https://example.com and http://test.org/path and ![image](https://example.com/img.png)';
        $links = $callable($content);

        static::assertContains('https://example.com', $links);
        static::assertContains('http://test.org/path', $links);
        static::assertNotContains('https://example.com/img.png', $links);
    }

    public function testIsImageUrlFilter(): void
    {
        $filters = array_values(array_filter(
            $this->extension->getFilters(),
            static fn($f) => $f->getName() === 'is_image_url',
        ));
        static::assertNotEmpty($filters);
        $callable = $filters[0]->getCallable();
        static::assertIsCallable($callable);

        static::assertTrue($callable('https://example.com/image.png'));
        static::assertTrue($callable('https://example.com/photo.jpeg'));
        static::assertFalse($callable('https://example.com/document.pdf'));
        static::assertFalse($callable(''));
        static::assertFalse($callable(null));
    }
}
