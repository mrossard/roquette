<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Service\Group\GroupProviderInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ChannelMembersDataProvider
{
    public function __construct(
        private GroupProviderInterface $groupProvider,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @return array{
     *     activeChannel: Channel,
     *     resolvedSubscriptions: list<array{identifier: string, name: string, isGroupChannel: bool}>,
     *     groupMembers: array<string|int, array{user?: User, username?: string, viaGroup: string, isRegistered: bool}>
     * }
     */
    public function getMembersModalData(Channel $channel): array
    {
        $resolvedSubscriptions = [];
        $groupMembers = [];
        $resolvedUserIds = [];

        foreach ($channel->getMembers() as $member) {
            $memberId = $member->getId();
            if ($memberId !== null) {
                $resolvedUserIds[$memberId] = true;
            }
        }

        foreach ($channel->getGroupSubscriptions() as $subscription) {
            $groupIdentifier = $subscription->getGroupIdentifier();
            $group = $this->groupProvider->getGroupByIdentifier($groupIdentifier);
            $groupName = $group !== null ? $group->name : $groupIdentifier;

            $resolvedSubscriptions[] = [
                'identifier' => $groupIdentifier,
                'name' => $groupName,
                'isGroupChannel' => $subscription->isGroupChannel(),
            ];

            $resolved = $this->resolveGroupMembers($groupIdentifier);
            foreach ($resolved['users'] as $user) {
                $userId = $user->getId();
                if ($userId !== null && array_key_exists($userId, $resolvedUserIds)) {
                    continue;
                }

                $key = $userId ?? $user->getUsername();
                $groupMembers[$key] = [
                    'user' => $user,
                    'viaGroup' => $groupName,
                    'isRegistered' => true,
                ];
            }

            foreach ($resolved['externalUsernames'] as $username) {
                $groupMembers['ext-' . $username] = [
                    'username' => $username,
                    'viaGroup' => $groupName,
                    'isRegistered' => false,
                ];
            }
        }

        uasort($groupMembers, static fn(array $a, array $b): int => strcasecmp(
            self::getMemberSortName($a),
            self::getMemberSortName($b),
        ));

        return [
            'activeChannel' => $channel,
            'resolvedSubscriptions' => $resolvedSubscriptions,
            'groupMembers' => $groupMembers,
        ];
    }

    /**
     * @return array{users: list<User>, externalUsernames: list<string>}
     */
    public function resolveGroupMembers(string $groupIdentifier): array
    {
        $localGroup = $this->entityManager
            ->getRepository(UserGroup::class)
            ->findOneBy(['groupIdentifier' => $groupIdentifier]);

        if ($localGroup !== null) {
            /** @var list<User> $members */
            $members = array_values($localGroup->getMembers()->toArray());

            return [
                'users' => $members,
                'externalUsernames' => [],
            ];
        }

        $externalUsernames = $this->groupProvider->getGroupMembers($groupIdentifier);
        $users = [];
        if ($externalUsernames !== []) {
            /** @var list<User> $users */
            $users = $this->entityManager->getRepository(User::class)->findBy(['username' => $externalUsernames]);
        }

        $foundUsernames = array_map(static fn(User $u): string => $u->getUsername(), $users);
        $unregistered = array_diff($externalUsernames, $foundUsernames);

        return [
            'users' => $users,
            'externalUsernames' => array_values($unregistered),
        ];
    }

    /**
     * @param array{user?: User, username?: string, isRegistered: bool} $memberItem
     */
    private static function getMemberSortName(array $memberItem): string
    {
        if (!$memberItem['isRegistered']) {
            return $memberItem['username'] ?? '';
        }

        $user = $memberItem['user'] ?? null;
        if ($user === null) {
            return '';
        }

        $displayName = $user->getDisplayName();
        if ($displayName !== null && $displayName !== '') {
            return $displayName;
        }

        return $user->getUsername();
    }
}
