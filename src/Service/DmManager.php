<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\User;
use App\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Contracts\Translation\TranslatorInterface;

class DmManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ChannelRepository $channelRepository,
        private readonly TranslatorInterface $translator,
        private readonly ?RobotUserProvider $robotUserProvider = null,
    ) {}

    public function getOrCreateDm(User $currentUser, User $partner): Channel
    {
        if ($partner->getId() === $currentUser->getId()) {
            throw new InvalidArgumentException($this->translator->trans(
                'Vous ne pouvez pas envoyer de message direct à vous-même.',
            ));
        }

        $dmChannel = $this->channelRepository->findDmBetween($currentUser, $partner);
        if ($dmChannel === null) {
            return $this->createDmChannel($currentUser, $partner);
        }

        $this->ensureMemberInDm($dmChannel, $currentUser);

        return $dmChannel;
    }

    public function createDmChannel(User $currentUser, User $partner): Channel
    {
        $dmChannel = new Channel();
        $dmChannel->setIsPrivate(true);
        $dmChannel->setIsDm(true);
        $dmChannel->setSlug($this->generateDmSlug($currentUser, $partner));
        $dmChannel->setName(sprintf('%s & %s', $currentUser->getUsername(), $partner->getUsername()));
        $dmChannel->setDescription(sprintf(
            'Conversation privée entre %s et %s',
            $currentUser->getUsername(),
            $partner->getUsername(),
        ));
        $dmChannel->setCreator($currentUser);
        $dmChannel->addMember($currentUser);
        $dmChannel->addMember($partner);

        $this->entityManager->persist($dmChannel);
        $this->entityManager->flush();

        return $dmChannel;
    }

    public function ensureMemberInDm(Channel $dmChannel, User $user): void
    {
        if (!$dmChannel->getMembers()->contains($user)) {
            $dmChannel->addMember($user);
            $this->entityManager->flush();
        }
    }

    public function generateDmSlug(User $user1, User $user2): string
    {
        if ($this->robotUserProvider !== null) {
            if ($this->robotUserProvider->isRobotUser($user1)) {
                return $this->robotUserProvider->getDmChannelSlug($user2);
            }
            if ($this->robotUserProvider->isRobotUser($user2)) {
                return $this->robotUserProvider->getDmChannelSlug($user1);
            }
        }

        $minId = min((int) $user1->getId(), (int) $user2->getId());
        $maxId = max((int) $user1->getId(), (int) $user2->getId());

        return sprintf('dm-%d-%d', $minId, $maxId);
    }
}
