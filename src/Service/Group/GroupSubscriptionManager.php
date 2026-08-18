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
        $this->assertValidGroupIdentifier($groupIdentifier);

        $groupSubscription = new GroupSubscription();
        $groupSubscription->setGroupIdentifier($groupIdentifier);
        $groupSubscription->setIsGroupChannel(false);
        $channel->addGroupSubscription($groupSubscription);

        return $groupSubscription;
    }

    /**
     * Attaches an official group subscription to a channel after validating uniqueness.
     */
    public function attachOfficialGroupSubscription(Channel $channel, string $groupIdentifier): GroupSubscription
    {
        $this->assertValidGroupIdentifier($groupIdentifier);
        $this->assertNoOfficialGroupChannel($groupIdentifier);

        $groupSubscription = new GroupSubscription();
        $groupSubscription->setGroupIdentifier($groupIdentifier);
        $groupSubscription->setIsGroupChannel(true);
        $channel->addGroupSubscription($groupSubscription);

        return $groupSubscription;
    }

    private function assertValidGroupIdentifier(string $groupIdentifier): void
    {
        if ($groupIdentifier === '') {
            throw new InvalidArgumentException($this->translator->trans('Identifiant de groupe invalide.'));
        }
    }

    private function assertNoOfficialGroupChannel(string $groupIdentifier): void
    {
        $existingGroupSub = $this->entityManager
            ->getRepository(GroupSubscription::class)
            ->findOneBy([
                'groupIdentifier' => $groupIdentifier,
                'isGroupChannel' => true,
            ]);

        if ($existingGroupSub !== null) {
            throw new InvalidArgumentException($this->translator->trans('Ce groupe possède déjà un canal officiel.'));
        }
    }

    public function subscribe(Channel $channel, string $groupIdentifier): ?GroupSubscription
    {
        $groupIdentifier = trim($groupIdentifier);
        if ($groupIdentifier === '') {
            return null;
        }

        $existingSub = $this->findExistingSubscription($channel, $groupIdentifier);
        if ($existingSub !== null) {
            return $existingSub;
        }

        $sub = $this->attachGroupSubscription($channel, $groupIdentifier);
        $this->entityManager->persist($sub);
        $this->entityManager->flush();

        return $sub;
    }

    public function subscribeOfficial(Channel $channel, string $groupIdentifier): ?GroupSubscription
    {
        $groupIdentifier = trim($groupIdentifier);
        if ($groupIdentifier === '') {
            return null;
        }

        $existingSub = $this->findExistingSubscription($channel, $groupIdentifier);
        if ($existingSub !== null) {
            return $existingSub;
        }

        $sub = $this->attachOfficialGroupSubscription($channel, $groupIdentifier);
        $this->entityManager->persist($sub);
        $this->entityManager->flush();

        return $sub;
    }

    private function findExistingSubscription(Channel $channel, string $groupIdentifier): ?GroupSubscription
    {
        return $this->entityManager
            ->getRepository(GroupSubscription::class)
            ->findOneBy([
                'channel' => $channel,
                'groupIdentifier' => $groupIdentifier,
            ]);
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
