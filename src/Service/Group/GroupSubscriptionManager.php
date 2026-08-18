<?php

declare(strict_types=1);

namespace App\Service\Group;

use App\Entity\Channel;
use App\Entity\GroupSubscription;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class GroupSubscriptionManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
    ) {}

    /**
     * Attaches a standard group subscription to a channel.
     */
    public function attachGroupSubscription(Channel $channel, string $groupIdentifier): GroupSubscription
    {
        return $this->doAttachGroupSubscription($channel, $groupIdentifier, false);
    }

    /**
     * Attaches an official group subscription to a channel after validating uniqueness.
     */
    public function attachOfficialGroupSubscription(Channel $channel, string $groupIdentifier): GroupSubscription
    {
        return $this->doAttachGroupSubscription($channel, $groupIdentifier, true);
    }

    private function doAttachGroupSubscription(Channel $channel, string $groupIdentifier, bool $isGroupChannel): GroupSubscription
    {
        if ($groupIdentifier === '') {
            throw new InvalidArgumentException($this->translator->trans('Identifiant de groupe invalide.'));
        }

        if ($isGroupChannel) {
            $existingGroupSub = $this->entityManager
                ->getRepository(GroupSubscription::class)
                ->findOneBy([
                    'groupIdentifier' => $groupIdentifier,
                    'isGroupChannel' => true,
                ]);

            if ($existingGroupSub !== null) {
                throw new InvalidArgumentException($this->translator->trans(
                    'Ce groupe possède déjà un canal officiel.',
                ));
            }
        }

        $groupSubscription = new GroupSubscription();
        $groupSubscription->setGroupIdentifier($groupIdentifier);
        $groupSubscription->setIsGroupChannel($isGroupChannel);
        $channel->addGroupSubscription($groupSubscription);

        return $groupSubscription;
    }

    public function subscribe(Channel $channel, string $groupIdentifier): ?GroupSubscription
    {
        return $this->doSubscribe($channel, $groupIdentifier, false);
    }

    public function subscribeOfficial(Channel $channel, string $groupIdentifier): ?GroupSubscription
    {
        return $this->doSubscribe($channel, $groupIdentifier, true);
    }

    private function doSubscribe(Channel $channel, string $groupIdentifier, bool $isOfficial): ?GroupSubscription
    {
        $groupIdentifier = trim($groupIdentifier);
        if ($groupIdentifier === '') {
            return null;
        }

        $existingSub = $this->entityManager
            ->getRepository(GroupSubscription::class)
            ->findOneBy([
                'channel' => $channel,
                'groupIdentifier' => $groupIdentifier,
            ]);

        if ($existingSub !== null) {
            return $existingSub;
        }

        $sub = $this->doAttachGroupSubscription($channel, $groupIdentifier, $isOfficial);
        $this->entityManager->persist($sub);
        $this->entityManager->flush();

        return $sub;
    }

    public function unsubscribe(Channel $channel, int $subscriptionId): bool
    {
        $subscription = $this->entityManager->getRepository(GroupSubscription::class)->find($subscriptionId);
        if (!$subscription || $subscription->getChannel() !== $channel) {
            return false;
        }

        $channel->removeGroupSubscription($subscription);
        $this->entityManager->remove($subscription);
        $this->entityManager->flush();

        return true;
    }

    /**
     * @return list<array{id: int|null, identifier: string, name: string, isGroupChannel: bool}>
     */
    public function getResolvedSubscriptions(Channel $channel, GroupProviderInterface $groupProvider): array
    {
        $resolved = [];
        foreach ($channel->getGroupSubscriptions() as $sub) {
            $grp = $groupProvider->getGroupByIdentifier($sub->getGroupIdentifier());
            $resolved[] = [
                'id' => $sub->getId(),
                'identifier' => $sub->getGroupIdentifier(),
                'name' => $grp ? $grp->name : $sub->getGroupIdentifier(),
                'isGroupChannel' => $sub->isGroupChannel(),
            ];
        }

        return $resolved;
    }
}
