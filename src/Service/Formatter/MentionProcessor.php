<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Entity\Channel;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;

final class MentionProcessor
{
    /** @var array<string, User|null> */
    private array $userCache = [];

    /** @var array<string, Channel|null> */
    private array $channelSlugCache = [];

    public function __construct(
        private readonly Security $security,
        private readonly UserRepository $userRepository,
        private readonly ChannelRepository $channelRepository,
    ) {}

    public function process(string $html): string
    {
        $this->preloadReferences($html);
        $html = $this->renderMentions($html);
        return $this->renderChannelReferences($html);
    }

    private function preloadReferences(string $html): void
    {
        $unknownUsernames = [];
        if (preg_match_all('/@([a-zA-Z0-9_\-]+(?:\.[a-zA-Z0-9_\-]+)*)/u', $html, $m)) {
            foreach (array_unique($m[1]) as $username) {
                if (\array_key_exists($username, $this->userCache)) {
                    continue;
                }
                $unknownUsernames[] = $username;
            }
        }
        if ($unknownUsernames !== []) {
            foreach ($this->userRepository->findBy(['username' => $unknownUsernames]) as $user) {
                $this->userCache[$user->getUsername()] = $user;
            }
            foreach ($unknownUsernames as $u) {
                if (\array_key_exists($u, $this->userCache)) {
                    continue;
                }
                $this->userCache[$u] = null;
            }
        }

        $unknownSlugs = [];
        if (preg_match_all('/#([a-zA-Z0-9_-]+)/', $html, $m)) {
            foreach (array_unique($m[1]) as $slug) {
                if (\array_key_exists($slug, $this->channelSlugCache)) {
                    continue;
                }
                $unknownSlugs[] = $slug;
            }
        }
        if ($unknownSlugs !== []) {
            foreach ($this->channelRepository->findBy(['slug' => $unknownSlugs, 'isDm' => false]) as $ch) {
                $this->channelSlugCache[$ch->getSlug()] = $ch;
            }
            foreach ($unknownSlugs as $s) {
                if (\array_key_exists($s, $this->channelSlugCache)) {
                    continue;
                }
                $this->channelSlugCache[$s] = null;
            }
        }
    }

    private function renderMentions(string $html): string
    {
        $currentUser = $this->security->getUser();
        $currentUsername = $currentUser?->getUserIdentifier();

        return preg_replace_callback(
            '/@([a-zA-Z0-9_\-]+(?:\.[a-zA-Z0-9_\-]+)*)/u',
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
                $rawDisplayName = $user->getDisplayName();
                $displayName = ($rawDisplayName !== null && $rawDisplayName !== '') ? $rawDisplayName : $user->getUsername();
                $url = '/dm/' . urlencode($user->getUsername());

                return (
                    '<a href="'
                    . $url
                    . '" class="'
                    . $class
                    . '" hx-boost="false">@'
                    . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8')
                    . '</a>'
                );
            },
            $html,
        );
    }

    private function renderChannelReferences(string $html): string
    {
        return preg_replace_callback(
            '/#([a-zA-Z0-9_-]+)(?:\?jumpTo=(\d+))?/',
            function ($matches) {
                $slug = $matches[1];
                $jumpToId = $matches[2] ?? null;

                if (!array_key_exists($slug, $this->channelSlugCache)) {
                    $this->channelSlugCache[$slug] = $this->channelRepository->findOneBy([
                        'slug' => $slug,
                        'isDm' => false,
                    ]);
                }
                $channel = $this->channelSlugCache[$slug];
                if ($channel) {
                    $currentUser = $this->security->getUser();
                    if ($channel->isPrivate()) {
                        if (!$currentUser || !$channel->getMembers()->contains($currentUser)) {
                            return '#' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
                        }
                    }
                    $url = '/channels/' . $slug . ($jumpToId ? '?jumpTo=' . $jumpToId : '');

                    return (
                        '<a href="'
                        . $url
                        . '" class="channel-ref" hx-boost="false">#'
                        . htmlspecialchars($channel->getName(), ENT_QUOTES, 'UTF-8')
                        . ($jumpToId ? ' (voir le message)' : '')
                        . '</a>'
                    );
                }

                return '#' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
            },
            $html,
        );
    }
}
