<?php

declare(strict_types=1);

namespace App\Service\Group;

use App\Entity\User;

interface GroupProviderInterface
{
    /**
     * @return GroupDTO[]
     */
    public function getGroups(string $searchQuery = ''): array;

    /**
     * @return GroupDTO[]
     */
    public function getGroupsForUser(User $user): array;

    public function getGroupByIdentifier(string $identifier): ?GroupDTO;

    /**
     * @return string[] List of usernames in the group
     */
    public function getGroupMembers(string $groupIdentifier): array;
}
