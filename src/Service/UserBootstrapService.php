<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class UserBootstrapService
{
    private array $bootstrappedUsers = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
    ) {}

    public function bootstrap(User $user): void
    {
        $userId = $user->getId();
        if ($userId === null) {
            return;
        }

        // Avoid duplicate runs in the same request
        if (isset($this->bootstrappedUsers[$userId])) {
            return;
        }

        // Try to use session to avoid running database queries on every request
        try {
            $session = $this->requestStack->getSession();
            if ($session->get('bootstrapped_' . $userId)) {
                $this->bootstrappedUsers[$userId] = true;
                return;
            }
        } catch (\Symfony\Component\HttpFoundation\Exception\SessionNotFoundException) {
            $session = null;
        }

        // Run the actual bootstrap logic
        $this->doBootstrap($user);

        $this->bootstrappedUsers[$userId] = true;
        if ($session !== null) {
            $session->set('bootstrapped_' . $userId, true);
        }
    }

    private function doBootstrap(User $user): void
    {
        $needsFlush = false;

        $generalName = $this->translator->trans('channel.general.name', [], 'messages');
        $generalDesc = $this->translator->trans('channel.general.description', [], 'messages');
        $assistantName = $this->translator->trans('channel.assistant.name', [], 'messages');
        $assistantDesc = $this->translator->trans('channel.assistant.description', [], 'messages');

        // 1. Ensure general channel
        $general = $this->entityManager->getRepository(Channel::class)->findOneBy(['slug' => 'general']);
        if (!$general) {
            $general = new Channel();
            $general->setName($generalName);
            $general->setSlug('general');
            $general->setDescription($generalDesc);
            $this->entityManager->persist($general);
            $needsFlush = true;
        } else {
            // Update translation if language changed and it needs sync
            if ($general->getName() !== $generalName && $generalName !== 'channel.general.name') {
                $general->setName($generalName);
                $general->setDescription($generalDesc);
                $needsFlush = true;
            }
        }

        // 2. Ensure general membership
        if (!$general->getMembers()->contains($user)) {
            $general->addMember($user);
            $needsFlush = true;
        }

        // 3. Ensure robot user
        $robotUser = $this->entityManager->getRepository(User::class)->findOneBy(['username' => 'robot-roquette']);
        if (!$robotUser) {
            $robotUser = new User();
            $robotUser->setUsername('robot-roquette');
            $robotUser->setDisplayName($assistantName);
            $robotUser->setRoles(['ROLE_USER']);
            // Securely hash a dummy random password
            $hashedPassword = $this->passwordHasher->hashPassword($robotUser, bin2hex(random_bytes(16)));
            $robotUser->setPassword($hashedPassword);
            $this->entityManager->persist($robotUser);
            $needsFlush = true;
        } else {
            if ($robotUser->getDisplayName() !== $assistantName && $assistantName !== 'channel.assistant.name') {
                $robotUser->setDisplayName($assistantName);
                $needsFlush = true;
            }
        }

        // 4. Ensure robot DM channel
        $robotSlug = 'dm-robot-roquette-' . $user->getSlug();
        $robotChannel = $this->entityManager->getRepository(Channel::class)->findOneBy(['slug' => $robotSlug]);
        if (!$robotChannel) {
            $robotChannel = new Channel();
            $robotChannel->setName($assistantName);
            $robotChannel->setSlug($robotSlug);
            $robotChannel->setDescription($assistantDesc);
            $robotChannel->setIsPrivate(true);
            $robotChannel->setIsDm(true);
            $robotChannel->addMember($user);
            $robotChannel->addMember($robotUser);
            $this->entityManager->persist($robotChannel);
            $needsFlush = true;
        } else {
            $channelNeedsFlush = false;
            if ($robotChannel->getName() !== $assistantName && $assistantName !== 'channel.assistant.name') {
                $robotChannel->setName($assistantName);
                $robotChannel->setDescription($assistantDesc);
                $channelNeedsFlush = true;
            }
            if (!$robotChannel->getMembers()->contains($user)) {
                $robotChannel->addMember($user);
                $channelNeedsFlush = true;
            }
            if (!$robotChannel->getMembers()->contains($robotUser)) {
                $robotChannel->addMember($robotUser);
                $channelNeedsFlush = true;
            }
            if ($channelNeedsFlush) {
                $needsFlush = true;
            }
        }

        if ($needsFlush) {
            $this->entityManager->flush();
        }
    }
}
