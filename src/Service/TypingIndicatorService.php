<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\User;
use Symfony\Contracts\Cache\CacheInterface;

class TypingIndicatorService
{
    private const TTL = 6;

    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    public function startTyping(Channel $channel, User $user): void
    {
        $cacheKey = 'channel_typing_' . $channel->getSlug();
        $typingUsers = $this->cache->get($cacheKey, static fn() => []);

        $displayName =
            $user->getDisplayName() !== null && $user->getDisplayName() !== ''
                ? $user->getDisplayName()
                : $user->getUsername();

        $typingUsers[$user->getUsername()] = [
            'name' => $displayName,
            'expires_at' => time() + self::TTL,
        ];

        $this->saveCleanedTypingUsers($cacheKey, $typingUsers);
    }

    public function stopTyping(Channel $channel, User $user): void
    {
        $cacheKey = 'channel_typing_' . $channel->getSlug();
        $typingUsers = $this->cache->get($cacheKey, static fn() => []);

        unset($typingUsers[$user->getUsername()]);

        $this->saveCleanedTypingUsers($cacheKey, $typingUsers);
    }


    private function saveCleanedTypingUsers(string $cacheKey, array $typingUsers): void
    {
        $now = time();
        foreach ($typingUsers as $username => $info) {
            if (($info['expires_at'] ?? 0) >= $now) {
                continue;
            }

            unset($typingUsers[$username]);
        }

        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, static fn() => $typingUsers);
    }

    /**
     * @return list<string> Display names of other users currently typing in the channel.
     */
    public function getTypingUsers(Channel $channel, ?User $currentUser = null): array
    {
        $cacheKey = 'channel_typing_' . $channel->getSlug();
        $typingUsers = $this->cache->get($cacheKey, static fn() => []);

        $now = time();
        $changed = false;
        foreach ($typingUsers as $username => $info) {
            if (($info['expires_at'] ?? 0) >= $now) {
                continue;
            }

            unset($typingUsers[$username]);
            $changed = true;
        }

        if ($changed) {
            $this->cache->delete($cacheKey);
            $this->cache->get($cacheKey, static fn() => $typingUsers);
        }

        if ($currentUser !== null) {
            unset($typingUsers[$currentUser->getUsername()]);
        }

        return array_map(static fn($info) => $info['name'], array_values($typingUsers));
    }
}
