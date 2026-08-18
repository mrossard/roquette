<?php

declare(strict_types=1);

namespace App\Service\Group;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Enum\AuditAction;
use App\Repository\UserGroupRepository;
use App\Repository\UserRepository;
use App\Service\AuditLoggerService;
use App\Service\WorkspaceManager;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Manages user groups lifecycle: local creation, external imports, member and admin assignments.
 */
class UserGroupManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserGroupRepository $userGroupRepository,
        private readonly UserRepository $userRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly GroupProviderInterface $groupProvider,
        private readonly AuditLoggerService $auditLogger,
        private readonly TranslatorInterface $translator,
    ) {}

    public function createLocalGroup(string $name, User $creator): UserGroup
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException($this->translator->trans('Le nom du groupe ne peut pas être vide.'));
        }

        $groupIdentifier = 'local-group-' . uniqid();

        $userGroup = new UserGroup();
        $userGroup->setName($name);
        $userGroup->setGroupIdentifier($groupIdentifier);

        // Auto-create official workspace
        $workspace = $this->workspaceManager->create($name, 'Espace de travail officiel du groupe ' . $name, $creator);
        $userGroup->setWorkspace($workspace);
        $workspace->setUserGroup($userGroup);

        $userGroup->addAdministrator($creator);

        $this->entityManager->persist($userGroup);
        $this->entityManager->flush();

        $this->auditLogger->log(AuditAction::GROUP_CREATE, $creator, [
            'group_id' => $userGroup->getId(),
            'group_name' => $name,
            'group_identifier' => $groupIdentifier,
        ]);

        return $userGroup;
    }

    public function importGroup(string $identifier, string $name, User $creator): UserGroup
    {
        $identifier = trim($identifier);
        $name = trim($name);

        if ($identifier === '' || $name === '') {
            throw new InvalidArgumentException($this->translator->trans('Paramètres d\'import invalides.'));
        }

        $existing = $this->userGroupRepository->findOneBy(['groupIdentifier' => $identifier]);
        if ($existing !== null) {
            throw new InvalidArgumentException($this->translator->trans(
                'Ce groupe est déjà importé dans l\'application.',
            ));
        }

        $userGroup = new UserGroup();
        $userGroup->setName($name);
        $userGroup->setGroupIdentifier($identifier);

        // Auto-create official workspace
        $workspace = $this->workspaceManager->create($name, 'Espace de travail officiel du groupe ' . $name, $creator);
        $userGroup->setWorkspace($workspace);
        $workspace->setUserGroup($userGroup);

        $userGroup->addAdministrator($creator);

        $this->entityManager->persist($userGroup);
        $this->entityManager->flush();

        $this->auditLogger->log(AuditAction::GROUP_CREATE, $creator, [
            'group_id' => $userGroup->getId(),
            'group_name' => $name,
            'group_identifier' => $identifier,
            'imported' => true,
        ]);

        return $userGroup;
    }

    public function deleteGroup(UserGroup $userGroup, User $currentUser): void
    {
        $name = $userGroup->getName();
        $groupId = $userGroup->getId();
        $groupIdentifier = $userGroup->getGroupIdentifier();

        $workspace = $userGroup->getWorkspace();
        if ($workspace !== null) {
            $this->entityManager->remove($workspace);
        }

        $this->entityManager->remove($userGroup);
        $this->entityManager->flush();

        $this->auditLogger->log(AuditAction::GROUP_DELETE, $currentUser, [
            'group_id' => $groupId,
            'group_name' => $name,
            'group_identifier' => $groupIdentifier,
        ]);
    }

    /**
     * @return list<array{username: string, isRegistered: bool, user: ?User}>
     */
    public function getExternalGroupMembers(UserGroup $userGroup): array
    {
        if (str_starts_with($userGroup->getGroupIdentifier(), 'local-group-')) {
            return [];
        }

        $externalUsernames = $this->groupProvider->getGroupMembers($userGroup->getGroupIdentifier());
        $registeredUsers = $this->userRepository->findBy(['username' => $externalUsernames]);

        $registeredUsersByUsername = [];
        foreach ($registeredUsers as $u) {
            $registeredUsersByUsername[$u->getUsername()] = $u;
        }

        $externalMembers = [];
        foreach ($externalUsernames as $username) {
            $isReg = array_key_exists($username, $registeredUsersByUsername);
            $externalMembers[] = [
                'username' => $username,
                'isRegistered' => $isReg,
                'user' => $isReg ? $registeredUsersByUsername[$username] : null,
            ];
        }

        usort($externalMembers, static fn($a, $b) => strcasecmp($a['username'], $b['username']));

        return $externalMembers;
    }

    /**
     * @return list<User>
     */
    public function searchInvitableMembers(UserGroup $userGroup, string $query, int $limit = 6): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $allUsers = $this->userRepository->getAllSortedByDisplayName();
        $currentMemberIds = array_map(static fn(User $u) => $u->getId(), $userGroup->getMembers()->toArray());

        $matches = [];
        $q = strtolower($query);
        foreach ($allUsers as $user) {
            if (in_array($user->getId(), $currentMemberIds, true)) {
                continue;
            }

            $username = strtolower($user->getUsername());
            $displayName = strtolower($user->getDisplayName() ?? '');

            if (str_contains($username, $q) || str_contains($displayName, $q)) {
                $matches[] = $user;
            }
        }

        return array_slice($matches, 0, $limit);
    }

    public function addMember(UserGroup $userGroup, User $user): void
    {
        $userGroup->addMember($user);
        $this->entityManager->flush();
    }

    public function removeMember(UserGroup $userGroup, User $user): void
    {
        $userGroup->removeMember($user);
        $this->entityManager->flush();
    }

    public function addAdministrator(UserGroup $userGroup, User $user): void
    {
        $userGroup->addAdministrator($user);
        $this->entityManager->flush();
    }

    public function removeAdministrator(UserGroup $userGroup, User $user): void
    {
        if ($userGroup->getAdministrators()->count() <= 1 && $userGroup->getAdministrators()->contains($user)) {
            throw new InvalidArgumentException($this->translator->trans(
                'Impossible de retirer le dernier administrateur du groupe.',
            ));
        }

        $userGroup->removeAdministrator($user);
        $this->entityManager->flush();
    }
}
