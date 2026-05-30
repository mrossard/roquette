<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\MessageFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class MessageFormatterTest extends TestCase
{
    private MessageFormatter $formatter;
    private Security $security;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->security->method('getUser')->willReturn(null);

        $this->formatter = new MessageFormatter($this->security);
    }

    // -------------------------------------------------------------------------
    // Inline formatting
    // -------------------------------------------------------------------------

    #[Test]
    public function formatEscapesHtml(): void
    {
        $result = $this->formatter->format('<script>alert("xss")</script>');

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    #[Test]
    public function formatRendersBoldWithDoubleStars(): void
    {
        $result = $this->formatter->format('**gras**');
        $this->assertStringContainsString('<strong>gras</strong>', $result);
    }

    #[Test]
    public function formatRendersBoldWithDoubleUnderscores(): void
    {
        $result = $this->formatter->format('__gras__');
        $this->assertStringContainsString('<strong>gras</strong>', $result);
    }

    #[Test]
    public function formatRendersItalicWithSingleStar(): void
    {
        $result = $this->formatter->format('*italique*');
        $this->assertStringContainsString('<em>italique</em>', $result);
    }

    #[Test]
    public function formatRendersStrikethrough(): void
    {
        $result = $this->formatter->format('~~barré~~');
        $this->assertStringContainsString('<del>barré</del>', $result);
    }

    #[Test]
    public function formatRendersInlineCode(): void
    {
        $result = $this->formatter->format('voici `du code` ici');
        $this->assertStringContainsString('<code class="message-inline-code">du code</code>', $result);
    }

    // -------------------------------------------------------------------------
    // Code blocks
    // -------------------------------------------------------------------------

    #[Test]
    public function formatRendersCodeBlock(): void
    {
        $result = $this->formatter->format("```\necho 'hello';\n```");
        $this->assertStringContainsString('<pre class="message-code-block">', $result);
        $this->assertStringContainsString('<code>', $result);
        // The formatter HTML-escapes content before parsing blocks, so ' becomes &#039;
        $this->assertStringContainsString('echo', $result);
        $this->assertStringContainsString('hello', $result);
    }

    #[Test]
    public function formatRendersCodeBlockWithLanguage(): void
    {
        $result = $this->formatter->format("```php\n\$x = 1;\n```");
        $this->assertStringContainsString('class="language-php"', $result);
    }

    #[Test]
    public function formatHandlesUnclosedCodeBlock(): void
    {
        // An unclosed code block should still be flushed as a code block
        $result = $this->formatter->format("```\ndu code sans fermeture");
        $this->assertStringContainsString('<pre class="message-code-block">', $result);
    }

    // -------------------------------------------------------------------------
    // Blockquotes
    // -------------------------------------------------------------------------

    #[Test]
    public function formatRendersBlockquote(): void
    {
        $result = $this->formatter->format('> une citation');
        $this->assertStringContainsString('<blockquote class="message-quote">', $result);
        $this->assertStringContainsString('une citation', $result);
    }

    #[Test]
    public function formatRendersMultiLineBlockquote(): void
    {
        $result = $this->formatter->format("> ligne 1\n> ligne 2");
        $this->assertStringContainsString('<blockquote class="message-quote">', $result);
        $this->assertStringContainsString('<br>', $result);
    }

    // -------------------------------------------------------------------------
    // Lists
    // -------------------------------------------------------------------------

    #[Test]
    public function formatRendersUnorderedList(): void
    {
        $result = $this->formatter->format("- item 1\n- item 2");
        $this->assertStringContainsString('<ul class="message-list">', $result);
        $this->assertStringContainsString('<li>item 1</li>', $result);
        $this->assertStringContainsString('<li>item 2</li>', $result);
        $this->assertStringContainsString('</ul>', $result);
    }

    #[Test]
    public function formatRendersOrderedList(): void
    {
        $result = $this->formatter->format("1. premier\n2. deuxième");
        $this->assertStringContainsString('<ol class="message-list">', $result);
        $this->assertStringContainsString('<li>premier</li>', $result);
        $this->assertStringContainsString('<li>deuxième</li>', $result);
        $this->assertStringContainsString('</ol>', $result);
    }

    #[Test]
    public function formatClosesPreviousListWhenTypeChanges(): void
    {
        $result = $this->formatter->format("- item\n1. other");
        $this->assertStringContainsString('</ul>', $result);
        $this->assertStringContainsString('<ol', $result);
    }

    // -------------------------------------------------------------------------
    // Links
    // -------------------------------------------------------------------------

    #[Test]
    public function formatRendersMarkdownLink(): void
    {
        $result = $this->formatter->format('[Symfony](https://symfony.com)');
        $this->assertStringContainsString('<a href="https://symfony.com"', $result);
        $this->assertStringContainsString('Symfony</a>', $result);
        $this->assertStringContainsString('target="_blank"', $result);
    }

    #[Test]
    public function formatRendersRawUrl(): void
    {
        $result = $this->formatter->format('Voir https://example.com pour plus d\'info');
        $this->assertStringContainsString('<a href="https://example.com"', $result);
    }

    #[Test]
    public function formatDoesNotCreateLinkForPlainText(): void
    {
        $result = $this->formatter->format('pas de lien ici');
        $this->assertStringNotContainsString('<a href', $result);
    }

    // -------------------------------------------------------------------------
    // Mentions
    // -------------------------------------------------------------------------

    #[Test]
    public function formatRendersMentionSpan(): void
    {
        $result = $this->formatter->format('Bonjour @alice !');
        $this->assertStringContainsString('<span class="mention">@alice</span>', $result);
    }

    #[Test]
    public function formatRendersSelfMentionWithMeMentionClass(): void
    {
        $user = $this->createStub(\Symfony\Component\Security\Core\User\UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('alice');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $formatter = new MessageFormatter($security);
        $result = $formatter->format('Bonjour @alice !');

        $this->assertStringContainsString('class="mention mention-me"', $result);
    }

    // -------------------------------------------------------------------------
    // Emoticons
    // -------------------------------------------------------------------------

    #[DataProvider('emoticonProvider')]
    #[Test]
    public function formatConvertsEmoticon(string $input, string $expectedEmoji): void
    {
        $result = $this->formatter->format($input);
        $this->assertStringContainsString($expectedEmoji, $result);
    }

    public static function emoticonProvider(): array
    {
        return [
            'smile ascii' => [':)', '🙂'],
            'grin ascii' => [':D', '😀'],
            'wink ascii' => [';)', '😉'],
            'sad ascii' => [':(', '🙁'],
            'tongue ascii' => [':P', '😛'],
            'cool ascii' => ['8)', '😎'],
        ];
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    #[Test]
    public function formatTrimsLeadingAndTrailingWhitespace(): void
    {
        $result = $this->formatter->format('   hello   ');
        $this->assertStringContainsString('hello', $result);
        $this->assertStringNotContainsString('   hello', $result);
    }

    #[Test]
    public function formatHandlesEmptyString(): void
    {
        $result = $this->formatter->format('');
        $this->assertSame('', $result);
    }

    #[Test]
    public function formatHandlesMultipleLineBreaks(): void
    {
        $result = $this->formatter->format("ligne 1\nligne 2\nligne 3");
        $this->assertStringContainsString('<br>', $result);
    }

    #[Test]
    public function formatRendersInlineImage(): void
    {
        $result = $this->formatter->format('![photo](https://example.com/img.png)');
        $this->assertStringContainsString('<img src="https://example.com/img.png"', $result);
        $this->assertStringContainsString('class="message-inline-image"', $result);
    }
}
