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
        private readonly UniqueSlugGenerator $slugGenerator,
        private readonly RobotUserProvider $robotUserProvider,
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

        $publicWorkspace = $this->ensurePublicWorkspace($user, $needsFlush);
        $this->ensureGeneralChannel($publicWorkspace, $generalName, $generalDesc, $needsFlush);
        $robotUser = $this->ensureRobotUser($assistantName, $needsFlush);
        $this->ensureRobotDmChannel($user, $robotUser, $assistantName, $assistantDesc, $needsFlush);

        if ($needsFlush) {
            $this->entityManager->flush();
        }
    }

    private function ensurePublicWorkspace(User $user, bool &$needsFlush): Workspace
    {
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

        if (!$publicWorkspace->isMember($user)) {
            $publicWorkspace->addMember($user);
            $needsFlush = true;
        }

        return $publicWorkspace;
    }

    private function ensureGeneralChannel(
        Workspace $publicWorkspace,
        string $generalName,
        string $generalDesc,
        bool &$needsFlush,
    ): void {
        $general = $this->entityManager
            ->getRepository(Channel::class)
            ->findOneBy([
                'workspace' => $publicWorkspace,
                'slug' => 'general',
            ]);

        if (!$general) {
            $generalSlug = $this->slugGenerator->generate(
                'general',
                'general',
                fn(string $s) => $this->entityManager->getRepository(Channel::class)->findOneBy(['slug' => $s]) !== null,
            );

            $general = new Channel();
            $general->setName($generalName);
            $general->setSlug($generalSlug);
            $general->setDescription($generalDesc);
            $general->setWorkspace($publicWorkspace);
            $this->entityManager->persist($general);
            $needsFlush = true;
        } elseif ($general->getName() !== $generalName && $generalName !== 'channel.general.name') {
            $general->setName($generalName);
            $general->setDescription($generalDesc);
            $needsFlush = true;
        }
    }

    private function ensureRobotUser(string $assistantName, bool &$needsFlush): User
    {
        $robotUser = $this->robotUserProvider->getRobotUser();
        if (!$robotUser) {
            $robotUser = new User();
            $robotUser->setUsername(User::ROBOT_USERNAME);
            $robotUser->setDisplayName($assistantName);
            $robotUser->setRoles(['ROLE_USER']);
            $hashedPassword = $this->passwordHasher->hashPassword($robotUser, bin2hex(random_bytes(16)));
            $robotUser->setPassword($hashedPassword);
            $this->entityManager->persist($robotUser);
            $needsFlush = true;
        } elseif ($robotUser->getDisplayName() !== $assistantName && $assistantName !== 'channel.assistant.name') {
            $robotUser->setDisplayName($assistantName);
            $needsFlush = true;
        }

        return $robotUser;
    }

    private function ensureRobotDmChannel(
        User $user,
        User $robotUser,
        string $assistantName,
        string $assistantDesc,
        bool &$needsFlush,
    ): void {
        $robotSlug = $this->robotUserProvider->getDmChannelSlug($user);
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
    }
}
