<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Channel;
use App\Repository\ChannelRepository;
use App\Service\MessageFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Extension Twig exposant les filtres `format_message` et `format_bytes`.
 *
 * La logique de formatage des messages est entièrement déléguée à
 * {@see MessageFormatter}, un service PHP pur testable unitairement.
 */
class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly MessageFormatter $formatter,
        private readonly TranslatorInterface $translator,
        private readonly ChannelRepository $channelRepository,
        private readonly string $mercureTopicPrefix,
    ) {}

    public function getFunctions(): array
    {
        return [
            new \Twig\TwigFunction('get_cached_link_preview', [
                \App\Twig\AppExtensionRuntime::class,
                'getCachedLinkPreview',
            ]),
            new \Twig\TwigFunction('get_subchannel', [$this, 'getSubchannel']),
            new \Twig\TwigFunction('get_user_mercure_topics', [$this, 'getUserMercureTopics']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('format_message', [$this->formatter, 'format'], ['is_safe' => ['html']]),
            new TwigFilter('wrap_emojis', [$this->formatter, 'wrapUnicodeEmojis'], ['is_safe' => ['html']]),
            new TwigFilter('format_bytes', [$this, 'formatBytes']),
            new TwigFilter('reaction_tooltip', [$this, 'formatReactionTooltip']),
            new TwigFilter('extract_external_links', [$this, 'extractExternalLinks']),
            new TwigFilter('is_image_url', [$this, 'isImageUrl']),
        ];
    }

    public function extractExternalLinks(?string $content): array
    {
        if (!$content) {
            return [];
        }

        // Strip Markdown image syntax ![alt](url) so that image URLs already
        // rendered by CommonMark are not extracted again as link previews.
        $content = preg_replace('/!\[.*?\]\(.*?\)/s', '', $content);

        // Match http/https URLs
        preg_match_all('/https?:\/\/[^\s\)<>"]+/i', $content, $matches);
        if ($matches[0] === []) {
            return [];
        }

        return array_values(array_unique($matches[0]));
    }

    /**
     * Vérifie si une URL pointe vers une image en se basant uniquement sur l'extension.
     * Utilisé dans les templates Twig pour éviter les placeholders HTMX pour les images directes.
     */
    public function isImageUrl(?string $url): bool
    {
        if (!$url) {
            return false;
        }
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'tiff', 'tif'];
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, $imageExtensions, true);
    }

    public function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= 1 << (10 * $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    public function formatReactionTooltip(array $usernames, string $emoji): string
    {
        $shortcode = \App\Service\EmojiMapping::getShortcode($emoji);
        $reactionName = $shortcode ? ':' . $shortcode . ':' : $emoji;

        if ($usernames === []) {
            return '';
        }

        $count = count($usernames);
        if ($count === 1) {
            return $this->translator->trans('%username% a réagi avec %reaction%', [
                '%username%' => $usernames[0],
                '%reaction%' => $reactionName,
            ]);
        }

        $lastUser = array_pop($usernames);
        $and = $this->translator->trans('et');
        $usersString = implode(', ', $usernames) . ' ' . $and . ' ' . $lastUser;

        return $this->translator->trans('%users% ont réagi avec %reaction%', [
            '%users%' => $usersString,
            '%reaction%' => $reactionName,
        ]);
    }

    private ?array $subchannelCache = null;

    public function resetSubchannelCache(): void
    {
        $this->subchannelCache = null;
    }

    public function getSubchannel(\App\Entity\Message $message): ?Channel
    {
        $messageId = $message->getId();
        if ($messageId === null) {
            return null;
        }

        if ($this->subchannelCache === null) {
            $this->subchannelCache = [];
            $em = $this->channelRepository->getEntityManager();
            $messages = $em->getUnitOfWork()->getIdentityMap()[\App\Entity\Message::class] ?? [];
            $messageIds = [];
            foreach ($messages as $msg) {
                if (!($msg instanceof \App\Entity\Message && $msg->getId() !== null)) {
                    continue;
                }

                $messageIds[] = $msg->getId();
            }

            if (!empty($messageIds)) {
                $channels = $this->channelRepository
                    ->createQueryBuilder('c')
                    ->where('c.parentMessage IN (:messageIds)')
                    ->setParameter('messageIds', $messageIds)
                    ->getQuery()
                    ->getResult();

                foreach ($messageIds as $id) {
                    $this->subchannelCache[$id] = null;
                }

                foreach ($channels as $channel) {
                    if ($channel->getParentMessage() === null) {
                        continue;
                    }

                    $this->subchannelCache[$channel->getParentMessage()->getId()] = $channel;
                }
            }
        }

        if (!array_key_exists($messageId, $this->subchannelCache)) {
            $channel = $this->channelRepository->findOneBy(['parentMessage' => $message]);
            $this->subchannelCache[$messageId] = $channel;
        }

        return $this->subchannelCache[$messageId];
    }

    public function getUserMercureTopics(\App\Entity\User $user): array
    {
        $topics = [
            $this->mercureTopicPrefix . '/users/' . $user->getUsername(),
            $this->mercureTopicPrefix . '/users/status',
        ];

        $channels = $this->channelRepository->findAllForUser($user);
        foreach ($channels as $ch) {
            $topics[] = $this->mercureTopicPrefix . '/channels/' . $ch->getSlug();
        }

        return $topics;
    }
}
