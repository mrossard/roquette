<?php

declare(strict_types=1);

namespace App\Dto\Channel;

use App\Entity\Channel;

final readonly class ResolvedChannelContext
{
    public function __construct(
        public Channel $channel,
        public bool $isMember,
    ) {}

    /**
     * Resolves whether notifications are enabled for the current channel and user context.
     *
     * @param array<int, array{notificationsEnabled?: ?bool}> $unreadCounts
     */
    public function resolveNotificationSetting(array $unreadCounts): bool
    {
        if ($this->isMember) {
            $activeUnread = $unreadCounts[$this->channel->getId()] ?? null;
            $notificationsEnabled = $activeUnread['notificationsEnabled'] ?? null;
            if ($notificationsEnabled !== null) {
                return (bool) $notificationsEnabled;
            }
        }

        return $this->channel->isDm();
    }
}
