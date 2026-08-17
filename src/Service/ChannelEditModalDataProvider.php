<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Repository\WebhookRepository;
use App\Service\Group\GroupProviderInterface;
use App\Service\Group\GroupSubscriptionManager;

final readonly class ChannelEditModalDataProvider
{
    public function __construct(
        private WebhookRepository $webhookRepository,
        private GroupProviderInterface $groupProvider,
        private GroupSubscriptionManager $groupSubscriptionManager,
    ) {}

    /**
     * @return array{
     *     activeChannel: Channel,
     *     webhooks: list<\App\Entity\Webhook>,
     *     groups: list<\App\Dto\GroupDto>,
     *     resolvedSubscriptions: list<array{id: int|null, identifier: string, name: string, isGroupChannel: bool}>
     * }
     */
    public function getEditModalData(Channel $channel): array
    {
        $webhooks = $this->webhookRepository->findBy(['channel' => $channel], ['createdAt' => 'ASC']);
        $groups = $this->groupProvider->getGroups();
        $resolvedSubscriptions = $this->groupSubscriptionManager->getResolvedSubscriptions($channel, $this->groupProvider);

        return [
            'activeChannel' => $channel,
            'webhooks' => $webhooks,
            'groups' => $groups,
            'resolvedSubscriptions' => $resolvedSubscriptions,
        ];
    }
}
