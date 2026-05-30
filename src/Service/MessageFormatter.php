<?php

namespace App\Service;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Bundle\SecurityBundle\Security;

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
        ':)'  => '🙂',
        ':-D' => '😀',
        ':D'  => '😀',
        ';-)' => '😉',
        ';)'  => '😉',
        ':-(' => '🙁',
        ':('  => '🙁',
        ':-P' => '😛',
        ':-p' => '😛',
        ':P'  => '😛',
        ':p'  => '😛',
        ':-O' => '😮',
        ':-o' => '😮',
        ':O'  => '😮',
        ':o'  => '😮',
        '&lt;3' => '❤️',
        '8)'  => '😎',
        'B)'  => '😎',
        'xD'  => '😆',
        'XD'  => '😆',
        ':-*' => '😘',
        ':*'  => '😘',
        ':\'(' => '😢',
        ';('  => '😢',
    ];

    private readonly MarkdownConverter $converter;

    public function __construct(private readonly Security $security)
    {
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
        $html = preg_replace_callback('/(?<!<pre class="message-code-block">)<code([^>]*)>/', static function ($matches) {
            $attrs = $matches[1];
            if (str_contains($attrs, 'class=')) {
                return $matches[0]; // Déjà une classe présente (ex: bloc de code avec langage)
            }
            return '<code class="message-inline-code"' . $attrs . '>';
        }, $html);

        // Ajouter target="_blank" rel="noopener noreferrer" à tous les liens externes
        $html = preg_replace_callback('/<a\s+href="([^"]+)"([^>]*)>/', static function ($matches) {
            $url = $matches[1];
            $extra = $matches[2];
            // Si target n'est pas déjà défini
            if (!str_contains($extra, 'target=')) {
                return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer"' . $extra . '>';
            }
            return $matches[0];
        }, $html);

        // Gérer les images inline avec le Lightbox custom
        // <img src="url" alt="alt" /> -> structure lightbox
        $html = preg_replace_callback('/<img\s+src="([^"]+)"\s+alt="([^"]*)"([^>]*)\/?>/', static function ($matches) {
            $url = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
            $alt = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
            return '<div class="message-inline-image-container"><img src="' . $url . '" alt="' . $alt . '" class="message-inline-image" onclick="openLightbox(this.src, \'' . addslashes($alt) . '\')"></div>';
        }, $html);

        // Gérer les mentions @username
        $currentUser     = $this->security->getUser();
        $currentUsername = $currentUser?->getUserIdentifier();
        
        $html = preg_replace_callback('/@(\w+)/', static function ($matches) use ($currentUsername) {
            $username = $matches[1];
            $isMe     = $currentUsername && strcasecmp($username, $currentUsername) === 0;
            $class    = $isMe ? 'mention mention-me' : 'mention';
            return '<span class="' . $class . '">@' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</span>';
        }, $html);

        // Remplacer les listes pour ajouter la classe 'message-list'
        $html = str_replace('<ul>', '<ul class="message-list">', $html);
        $html = str_replace('<ol>', '<ol class="message-list">', $html);

        // Remplacer les blockquotes pour ajouter la classe 'message-quote'
        return str_replace('<blockquote>', '<blockquote class="message-quote">', $html);
    }

    private function replaceEmoticons(string $content): string
    {
        // Échapper au préalable pour la comparaison avec &lt;3
        $safeContent = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');

        foreach (self::EMOTICONS as $emoticon => $emoji) {
            $quoted  = preg_quote($emoticon, '/');
            $pattern = '/(?<=^|\s)' . $quoted . '(?=$|\s|[\.!?,])/';
            $safeContent = preg_replace($pattern, $emoji, $safeContent);
        }

        // On décode avant d'envoyer à CommonMark pour qu'il parse correctement les caractères complexes
        return htmlspecialchars_decode($safeContent, ENT_QUOTES);
    }
}
