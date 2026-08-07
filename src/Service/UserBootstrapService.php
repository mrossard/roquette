<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\User;
use App\Entity\Workspace;
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
        if (\array_key_exists($userId, $this->bootstrappedUsers)) {
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

        // 0. Ensure public workspace exists (already created by migration, but safety check)
        $publicWorkspace = $this->entityManager->getRepository(Workspace::class)->findOneBy(['isPublic' => true]);
        if (!$publicWorkspace) {
            $publicWorkspace = new Workspace();
            $publicWorkspace->setName($this->translator->trans('workspace.public.name', [], 'messages'));
            $publicWorkspace->setSlug('public');
            $publicWorkspace->setDescription($this->translator->trans('workspace.public.description', [], 'messages'));
            $publicWorkspace->setIsPublic(true);
            $this->entityManager->persist($publicWorkspace);
            $needsFlush = true;
        }

        // Ensure user is a member of the public workspace
        if (!$publicWorkspace->isMember($user)) {
            $publicWorkspace->addMember($user);
            $needsFlush = true;
        }

        // 1. Ensure general channel exists in the public workspace
        $general = $this->entityManager
            ->getRepository(Channel::class)
            ->findOneBy([
                'workspace' => $publicWorkspace,
                'slug' => 'general',
            ]);
        if (!$general) {
            $generalSlug = 'general';
            $baseSlug = $generalSlug;
            $count = 1;
            while ($this->entityManager->getRepository(Channel::class)->findOneBy(['slug' => $generalSlug])) {
                $generalSlug = $baseSlug . '-' . rand(100, 999);
                if ($count++ > 20) {
                    $generalSlug = $baseSlug . '-' . uniqid();
                    break;
                }
            }

            $general = new Channel();
            $general->setName($generalName);
            $general->setSlug($generalSlug);
            $general->setDescription($generalDesc);
            $general->setWorkspace($publicWorkspace);
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

        // No need to add user as member of workspace channels — workspace membership grants access

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
