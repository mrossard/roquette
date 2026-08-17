<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Formatter\EmojiProcessor;
use App\Service\Formatter\EmoticonProcessor;
use App\Service\Formatter\HtmlDecorator;
use App\Service\Formatter\MentionProcessor;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Formate le contenu brut d'un message en HTML sécurisé.
 *
 * Utilise league/commonmark pour le parsing Markdown (GFM)
 * et applique une sanitization robuste tout en conservant les fonctionnalités
 * spécifiques de Roquette (mentions, émoticones, lightbox, etc.).
 */
class MessageFormatter
{
    private readonly MarkdownConverter $converter;

    public function __construct(
        private readonly EmoticonProcessor $emoticonProcessor,
        private readonly EmojiProcessor $emojiProcessor,
        private readonly MentionProcessor $mentionProcessor,
        private readonly HtmlDecorator $htmlDecorator,
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
        $trimmedContent = $this->emoticonProcessor->process($trimmedContent);

        // 2. Conversion Markdown (GFM) en HTML
        $html = $this->converter->convert($trimmedContent)->getContent();

        // 3. Décoration des éléments HTML (blocs de code, liens, lightbox images, listes, citations)
        $html = $this->htmlDecorator->decorate($html);

        // 4. Traitement des mentions et des références de canaux
        $html = $this->mentionProcessor->process($html);

        // 5. Remplacement des emojis (Unicode, shortcodes, personnalisés)
        $html = $this->emojiProcessor->processHtml($html);

        return trim($html);
    }

    /**
     * Enveloppe les emojis Unicode d'une balise span avec leur shortcode en title.
     */
    public function wrapUnicodeEmojis(string $text): string
    {
        return $this->emojiProcessor->wrapUnicodeEmojis($text);
    }
}
