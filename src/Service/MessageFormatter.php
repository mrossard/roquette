<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ChannelRepository;
use App\Repository\UserRepository;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Formate le contenu brut d'un message en HTML sécurisé.
 *
 * Utilise league/commonmark pour le parsing Markdown (GFM)
 * et applique une sanitization robuste tout en conservant les fonctionnalités
 * spécifiques de Roquette (mentions, émoticones, lightbox, etc.).
 */
class MessageFormatter
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

    private readonly MarkdownConverter $converter;
    private array $channelSlugCache = [];
    private array $userCache = [];

    public function __construct(
        private readonly Security $security,
        #[Autowire('%env(EMOJI_BASE_URL)%')]
        private readonly string $emojiBaseUrl,
        private readonly ChannelRepository $channelRepository,
        private readonly UserRepository $userRepository,
    ) {
        $config = [
            'html_input' => 'escape', // Échappe tout HTML brut fourni par l'utilisateur
            'allow_unsafe_links' => false,
            'renderer' => [
                'soft_break' => "<br>\n",
            ],
        ];

        $environment = new Environment($config);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        $this->converter = new MarkdownConverter($environment);
    }

    /**
     * Transforme le contenu brut d'un message en HTML sécurisé avec support Markdown complet (GFM).
     */
    public function format(string $content): string
    {
        $trimmedContent = trim($content);
        if ($trimmedContent === '') {
            return '';
        }

        // 1. Remplacement des émoticones simples
        $trimmedContent = $this->replaceEmoticons($trimmedContent);

        // 2. Conversion Markdown (GFM) en HTML
        $html = $this->converter->convert($trimmedContent)->getContent();

        // 3. Post-process du HTML pour préserver le comportement historique de Roquette
        $html = $this->postProcessHtml($html);

        return trim($html);
    }

    private function postProcessHtml(string $html): string
    {
        // Rendre les blocs de code conformes aux classes CSS existantes
        // <pre> -> <pre class="message-code-block">
        $html = str_replace('<pre>', '<pre class="message-code-block">', $html);

        // Rendre le code inline conforme aux classes CSS existantes
        // <code> (qui n'est pas précédé d'un <pre) -> <code class="message-inline-code">
        // Nous pouvons utiliser une regex pour cibler uniquement les <code> qui ne sont pas dans un <pre>
        $html = preg_replace_callback(
            '/(?<!<pre class="message-code-block">)<code([^>]*)>/',
            static function ($matches) {
                $attrs = $matches[1];
                if (str_contains($attrs, 'class=')) {
                    return $matches[0]; // Déjà une classe présente (ex: bloc de code avec langage)
                }
                return '<code class="message-inline-code"' . $attrs . '>';
            },
            $html,
        );

        // Ajouter target="_blank" rel="noopener noreferrer" à tous les liens externes
        $html = preg_replace_callback(
            '/<a\s+href="([^"]+)"([^>]*)>/',
            static function ($matches) {
                $url = $matches[1];
                $extra = $matches[2];
                // Si target n'est pas déjà défini
                if (!str_contains($extra, 'target=')) {
                    return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer"' . $extra . '>';
                }
                return $matches[0];
            },
            $html,
        );

        // Gérer les images inline avec le Lightbox custom
        // <img src="url" alt="alt" /> -> structure lightbox
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

        // Gérer les mentions @username
        $currentUser = $this->security->getUser();
        $currentUsername = $currentUser?->getUserIdentifier();

        $html = preg_replace_callback(
            '/@([a-zA-Z0-9_à-ÿÀ-Ÿ-]+)/u',
            function ($matches) use ($currentUsername) {
                $username = $matches[1];
                if (!array_key_exists($username, $this->userCache)) {
                    $this->userCache[$username] = $this->userRepository->findOneBy(['username' => $username]);
                }
                $user = $this->userCache[$username];
                if (!$user) {
                    return $matches[0];
                }

                $isMe = $currentUsername && strcasecmp($username, $currentUsername) === 0;
                $class = $isMe ? 'mention mention-me' : 'mention';
                $displayName = $user->getDisplayName() ?: $user->getUsername();
                $url = '/dm/' . urlencode($user->getUsername());

                return '<a href="' . $url . '" class="' . $class . '" hx-boost="false">@' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . '</a>';
            },
            $html,
        );

        // Gérer les références aux canaux #slug
        $html = preg_replace_callback(
            '/#([a-zA-Z0-9_-]+)/',
            function ($matches) {
                $slug = $matches[1];
                if (!array_key_exists($slug, $this->channelSlugCache)) {
                    $this->channelSlugCache[$slug] = $this->channelRepository->findOneBy(['slug' => $slug, 'isDm' => false]);
                }
                $channel = $this->channelSlugCache[$slug];
                if ($channel) {
                    $currentUser = $this->security->getUser();
                    if ($channel->isPrivate()) {
                        if (!$currentUser || !$channel->getMembers()->contains($currentUser)) {
                            return '#' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
                        }
                    }
                    $url = '/channels/' . $slug;

                    return (
                        '<a href="'
                        . $url
                        . '" class="channel-ref" hx-boost="false">#'
                        . htmlspecialchars($channel->getName(), ENT_QUOTES, 'UTF-8')
                        . '</a>'
                    );
                }

                return '#' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
            },
            $html,
        );

        // Remplacer les listes pour ajouter la classe 'message-list'
        $html = str_replace('<ul>', '<ul class="message-list">', $html);
        $html = str_replace('<ol>', '<ol class="message-list">', $html);

        // Remplacer les blockquotes pour ajouter la classe 'message-quote'
        $html = str_replace('<blockquote>', '<blockquote class="message-quote">', $html);

        // Remplacer les emojis personnalisés de mesdiscussions dans les nœuds de texte
        return $this->replaceCustomEmojisInHtml($html);
    }

    private function replaceCustomEmojisInHtml(string $html): string
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
                    if (str_starts_with($part, '</')) {
                        $inCodeOrPre = max(0, $inCodeOrPre - 1);
                    } else {
                        $inCodeOrPre++;
                    }
                }
            } else {
                if ($inCodeOrPre === 0) {
                    $part = $this->replaceShortcodes($part);
                    $part = $this->wrapUnicodeEmojis($part);
                    $part = $this->replaceCustomEmojis($part);
                    $part = $this->replaceRedfaceEmoji($part);
                }
            }
        }
        unset($part);

        return implode('', $parts);
    }

    private function replaceShortcodes(string $text): string
    {
        if (!str_contains($text, ':')) {
            return $text;
        }

        return preg_replace_callback(
            '/:([a-zA-Z0-9_\-\+]+):/',
            static function ($matches) {
                $shortcode = $matches[1];
                if (isset(EmojiMapping::MAPPING[$shortcode])) {
                    return EmojiMapping::MAPPING[$shortcode];
                }

                return $matches[0];
            },
            $text,
        );
    }

    private ?array $reverseEmojiMapping = null;

    private function getShortcodeForUnicodeEmoji(string $emoji): ?string
    {
        if ($this->reverseEmojiMapping === null) {
            $this->reverseEmojiMapping = [];
            foreach (EmojiMapping::MAPPING as $code => $unicode) {
                if (!isset($this->reverseEmojiMapping[$unicode])) {
                    $this->reverseEmojiMapping[$unicode] = $code;
                }
            }
        }
        return $this->reverseEmojiMapping[$emoji] ?? null;
    }

    public function wrapUnicodeEmojis(string $text): string
    {
        $pattern = '/(?:[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{1F1E6}-\x{1F1FF}\x{1F3FB}-\x{1F3FF}]\x{FE0F}?|\x{200D})+/u';
        return preg_replace_callback($pattern, function ($matches) {
            $emoji = $matches[0];
            $shortcode = $this->getShortcodeForUnicodeEmoji($emoji);
            if ($shortcode) {
                return '<span class="unicode-emoji" title=":' . htmlspecialchars($shortcode, ENT_QUOTES, 'UTF-8') . ':">' . $emoji . '</span>';
            }
            return '<span class="unicode-emoji">' . $emoji . '</span>';
        }, $text);
    }

    private function replaceCustomEmojis(string $text): string
    {
        if (empty($this->emojiBaseUrl) || !str_contains($text, '[:')) {
            return $text;
        }

        return preg_replace_callback(
            '/\[:([a-zA-Z0-9_\-\+: ]+)\]/',
            static function ($matches) {
                $code = $matches[1];

                $pos = strrpos($code, ':');
                if ($pos !== false) {
                    $name = substr($code, 0, $pos);
                    $dir = substr($code, $pos + 1);
                    $filename = basename($name . '.gif');
                    $webPath = '/emojis/' . rawurlencode($dir) . '/' . rawurlencode($filename);
                } else {
                    $filename = basename($code . '.gif');
                    $webPath = '/emojis/' . rawurlencode($filename);
                }

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

    private function replaceEmoticons(string $content): string
    {
        // Échapper au préalable pour la comparaison avec &lt;3
        if (!preg_match('/[:;8BxX&<]/', $content)) {
            return $content;
        }

        $safeContent = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');

        // Match all emoticons in a single pattern ordered by length descending.
        // We handle the single quote being escaped as &#039; by htmlspecialchars.
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

        // On décode avant d'envoyer à CommonMark pour qu'il parse correctement les caractères complexes
        return htmlspecialchars_decode($safeContent, ENT_QUOTES);
    }

    private function replaceRedfaceEmoji(string $text): string
    {
        if (empty($this->emojiBaseUrl)) {
            return $text;
        }

        $parsed = parse_url($this->emojiBaseUrl);
        $origin = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }
        $url = $origin . '/icones/redface.gif';

        $pattern = '/(?<=^|\s):-?o(?=$|\s|[\.!?,])/i';
        return preg_replace(
            $pattern,
            '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt=":o" title=":o" class="message-emoji" style="vertical-align: middle;" />',
            $text,
        );
    }
}
