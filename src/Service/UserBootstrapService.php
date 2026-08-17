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
        $generalName = $this->translator->trans('channel.general.name', [], 'messages');
        $generalDesc = $this->translator->trans('channel.general.description', [], 'messages');
        $assistantName = $this->translator->trans('channel.assistant.name', [], 'messages');
        $assistantDesc = $this->translator->trans('channel.assistant.description', [], 'messages');

        [$publicWorkspace, $wsChanged] = $this->ensurePublicWorkspace($user);
        $generalChanged = $this->ensureGeneralChannel($publicWorkspace, $generalName, $generalDesc);
        [$robotUser, $robotChanged] = $this->ensureRobotUser($assistantName);
        $dmChanged = $this->ensureRobotDmChannel($user, $robotUser, $assistantName, $assistantDesc);

        if ($wsChanged || $generalChanged || $robotChanged || $dmChanged) {
            $this->entityManager->flush();
        }
    }

    /**
     * @return array{0: Workspace, 1: bool}
     */
    private function ensurePublicWorkspace(User $user): array
    {
        $needsFlush = false;
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

        return [$publicWorkspace, $needsFlush];
    }

    private function ensureGeneralChannel(
        Workspace $publicWorkspace,
        string $generalName,
        string $generalDesc,
    ): bool {
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

            return true;
        }

        if ($general->getName() !== $generalName && $generalName !== 'channel.general.name') {
            $general->setName($generalName);
            $general->setDescription($generalDesc);

            return true;
        }

        return false;
    }

    /**
     * @return array{0: User, 1: bool}
     */
    private function ensureRobotUser(string $assistantName): array
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

            return [$robotUser, true];
        }

        if ($robotUser->getDisplayName() !== $assistantName && $assistantName !== 'channel.assistant.name') {
            $robotUser->setDisplayName($assistantName);

            return [$robotUser, true];
        }

        return [$robotUser, false];
    }

    private function ensureRobotDmChannel(
        User $user,
        User $robotUser,
        string $assistantName,
        string $assistantDesc,
    ): bool {
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

            return true;
        }

        $needsFlush = false;
        if ($robotChannel->getName() !== $assistantName && $assistantName !== 'channel.assistant.name') {
            $robotChannel->setName($assistantName);
            $robotChannel->setDescription($assistantDesc);
            $needsFlush = true;
        }
        if (!$robotChannel->getMembers()->contains($user)) {
            $robotChannel->addMember($user);
            $needsFlush = true;
        }
        if (!$robotChannel->getMembers()->contains($robotUser)) {
            $robotChannel->addMember($robotUser);
            $needsFlush = true;
        }

        return $needsFlush;
    }
}
