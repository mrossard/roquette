<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Channel;
use App\Repository\ChannelRepository;
use App\Repository\WorkspaceRepository;

/**
 * Resolves a channel referenced by name or slug, preferring the current workspace.
 */
final readonly class ChannelResolver
{
    public function __construct(
        private ChannelRepository $channelRepository,
        private WorkspaceRepository $workspaceRepository,
    ) {}

    public function resolve(string $channelSlug, ?int $workspaceId = null): ?Channel
    {
        $query = strtolower(trim($channelSlug));
        if ($query === '') {
            return null;
        }

        $workspace = null;
        if ($workspaceId !== null) {
            $workspace = $this->workspaceRepository->find($workspaceId);
        }

        if ($workspace !== null) {
            foreach ($workspace->getChannels() as $channel) {
                if (strtolower((string) $channel->getName()) === $query) {
                    return $channel;
                }
            }

            foreach ($workspace->getChannels() as $channel) {
                if (strtolower((string) $channel->getSlug()) === $query) {
                    return $channel;
                }
            }
        }

        $channel = $this->channelRepository->findOneBy(['slug' => $query]);
        if ($channel !== null) {
            return $channel;
        }

        return $this->channelRepository->findOneByNameOrSlugFuzzy($query);
    }

    /**
     * Resolves a channel within the user's accessible channel list, by exact
     * slug/name first, then by substring match.
     *
     * @param list<Channel> $channels
     */
    public function resolveFromList(string $query, array $channels): ?Channel
    {
        $normalized = strtolower(trim($query));
        if ($normalized === '') {
            return null;
        }

        return $this->matchExactChannel($normalized, $channels) ?? $this->matchPartialChannel($normalized, $channels);
    }

    /**
     * @param list<Channel> $channels
     */
    private function matchExactChannel(string $query, array $channels): ?Channel
    {
        foreach ($channels as $channel) {
            if (
                strtolower((string) $channel->getSlug()) === $query
                || strtolower((string) $channel->getName()) === $query
            ) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * @param list<Channel> $channels
     */
    private function matchPartialChannel(string $query, array $channels): ?Channel
    {
        foreach ($channels as $channel) {
            if (
                str_contains(strtolower((string) $channel->getName()), $query)
                || str_contains(strtolower((string) $channel->getSlug()), $query)
            ) {
                return $channel;
            }
        }

        return null;
    }
}
