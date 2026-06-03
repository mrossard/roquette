<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\MessageFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MessageFormatterTest extends TestCase
{
    private MessageFormatter $formatter;
    private Security $security;
    private HttpClientInterface $httpClient;
    private string $testEmojisDir;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->security->method('getUser')->willReturn(null);
        $this->httpClient = $this->createMock(HttpClientInterface::class);

        $this->testEmojisDir = __DIR__ . '/../../../var/test_emojis';

        $this->formatter = new MessageFormatter(
            $this->security,
            $this->httpClient,
            $this->testEmojisDir,
            'http://example.com/emojis'
        );
    }

    protected function tearDown(): void
    {
        $dir = $this->testEmojisDir . '/public/uploads/emojis';
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($dir);
        }
        $publicDir = $this->testEmojisDir . '/public/uploads';
        if (is_dir($publicDir)) {
            rmdir($publicDir);
        }
        $publicBase = $this->testEmojisDir . '/public';
        if (is_dir($publicBase)) {
            rmdir($publicBase);
        }
        if (is_dir($this->testEmojisDir)) {
            rmdir($this->testEmojisDir);
        }
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
    public function formatWrapsUnicodeEmojis(): void
    {
        $result = $this->formatter->format('*italique 🙂*');
        $this->assertStringContainsString('<em>italique <span class="unicode-emoji">🙂</span></em>', $result);
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

        $formatter = new MessageFormatter(
            $security,
            $this->httpClient,
            $this->testEmojisDir,
            'http://example.com/emojis'
        );
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

    // -------------------------------------------------------------------------
    // Custom Mesdiscussions Emojis
    // -------------------------------------------------------------------------

    #[Test]
    public function formatRendersCustomEmojiSuccessfullyDownloaded(): void
    {
        $response = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn('fake_gif_content');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'http://example.com/emojis/smile.gif')
            ->willReturn($response);

        $result = $this->formatter->format('Hello [:smile] !');
        $this->assertStringContainsString('<img src="/uploads/emojis/smile.gif" alt="[:smile]" title="[:smile]" class="message-emoji"', $result);
    }

    #[Test]
    public function formatRendersCustomEmojiWithSpacesSuccessfullyDownloaded(): void
    {
        $response = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn('fake_gif_content');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'http://example.com/emojis/doc%20petrus.gif')
            ->willReturn($response);

        $result = $this->formatter->format('Hello [:doc petrus] !');
        $this->assertStringContainsString('<img src="/uploads/emojis/doc%20petrus.gif" alt="[:doc petrus]" title="[:doc petrus]" class="message-emoji"', $result);
    }

    #[Test]
    public function formatDoesNotRenderCustomEmojiWhenDownloadFails(): void
    {
        $response = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'http://example.com/emojis/notfound.gif')
            ->willReturn($response);

        $result = $this->formatter->format('Hello [:notfound] !');
        $this->assertStringContainsString('Hello [:notfound] !', $result);
        $this->assertStringNotContainsString('<img', $result);
    }

    #[Test]
    public function formatDoesNotReplaceEmojiInCodeOrPreBlocks(): void
    {
        $this->httpClient->expects($this->never())->method('request');

        $result = $this->formatter->format("Voici du code `[:smile]` et un bloc :\n```\n[:smile]\n```");
        $this->assertStringContainsString('<code class="message-inline-code">[:smile]</code>', $result);
        $this->assertStringContainsString('[:smile]', $result);
        $this->assertStringNotContainsString('<img', $result);
    }

    #[Test]
    public function formatReplacesShortcodesWithUnicodeEmoji(): void
    {
        $result = $this->formatter->format('Hello :grin: and :smile:!');
        $this->assertStringContainsString('Hello <span class="unicode-emoji">😁</span> and <span class="unicode-emoji">😄</span>!', $result);
    }

    #[Test]
    public function formatDoesNotReplaceUnknownShortcodes(): void
    {
        $result = $this->formatter->format('Hello :unknown_shortcode_not_exists:!');
        $this->assertStringContainsString('Hello :unknown_shortcode_not_exists:!', $result);
        $this->assertStringNotContainsString('<span class="unicode-emoji">', $result);
    }

    #[Test]
    public function formatDoesNotReplaceShortcodesInCodeBlocks(): void
    {
        $result = $this->formatter->format('Code `:grin:` block');
        $this->assertStringContainsString('<code class="message-inline-code">:grin:</code>', $result);
        $this->assertStringNotContainsString('😁', $result);
    }

    #[Test]
    public function formatReplacesAllConfiguredEmoticons(): void
    {
        // Testing that all configured emoticons map to their correct emojis
        $emoticons = [
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
            ':O' => '😮',
            ':o' => '😮',
            '<3' => '❤️',
            '8)' => '😎',
            'B)' => '😎',
            'xD' => '😆',
            'XD' => '😆',
            ':-*' => '😘',
            ':*' => '😘',
            ":'(" => '😢',
            ';(' => '😢',
        ];

        foreach ($emoticons as $emoticon => $expectedEmoji) {
            $result = $this->formatter->format("Hello $emoticon !");
            $this->assertStringContainsString($expectedEmoji, $result, "Failed replacing emoticon: $emoticon");
        }
    }
}
