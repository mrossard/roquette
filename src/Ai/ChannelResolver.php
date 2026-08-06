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

        foreach ($this->channelRepository->findAll() as $channel) {
            if (
                strtolower((string) $channel->getSlug()) === $query
                || strtolower((string) $channel->getName()) === $query
                || str_contains(strtolower((string) $channel->getName()), $query)
            ) {
                return $channel;
            }
        }

        return null;
    }
}
