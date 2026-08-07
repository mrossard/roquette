<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Ai\ChannelResolver;
use App\Entity\Channel;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ChannelAccessService;

abstract readonly class AbstractAiTool implements AiToolInterface
{
    /**
     * @return array{error: string}|array{result: string}
     */
    protected function formatError(string $message): array
    {
        return ['error' => $message];
    }

    /**
     * @return array{result: string}
     */
    protected function formatSuccess(string $message): array
    {
        return ['result' => $message];
    }

    protected function resolveUser(UserRepository $userRepository, int $userId): ?User
    {
        return $userRepository->find($userId);
    }

    /**
     * Resolves a channel and checks if the user has access to it.
     *
     * @return array{channel: ?Channel, error: ?string}
     */
    protected function resolveChannelAndCheckAccess(
        ChannelResolver $channelResolver,
        ChannelAccessService $channelAccessService,
        string $channelSlug,
        ?User $user,
        ?int $workspaceId = null,
    ): array {
        $channel = $channelResolver->resolve($channelSlug, $workspaceId);
        if (!$channel) {
            return [
                'channel' => null,
                'error' => sprintf("Canal '%s' non trouvé ou vous n'y avez pas accès.", $channelSlug),
            ];
        }

        if ($user !== null && !$channelAccessService->canUserAccess($channel, $user)) {
            return [
                'channel' => null,
                'error' => sprintf("Vous n'avez pas accès au canal '%s'.", $channel->getName()),
            ];
        }

        return [
            'channel' => $channel,
            'error' => null,
        ];
    }
}
