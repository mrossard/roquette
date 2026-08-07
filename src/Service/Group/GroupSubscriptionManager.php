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
     * Attaches a group subscription to a channel after validating that official group channels are unique.
     */
    public function attachGroupSubscription(Channel $channel, string $groupIdentifier, bool $isGroupChannel): GroupSubscription
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
}
