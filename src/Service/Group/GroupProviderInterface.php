<?php

declare(strict_types=1);

namespace App\Service\Group;

use App\Dto\Group\GroupDto;
use App\Entity\User;

interface GroupProviderInterface
{
    /**
     * @return GroupDto[]
     */
    public function getGroups(string $searchQuery = ''): array;

    /**
     * @return GroupDto[]
     */
    public function getGroupsForUser(User $user): array;

    public function getGroupByIdentifier(string $identifier): ?GroupDto;

    /**
     * @return string[] List of usernames in the group
     */
    public function getGroupMembers(string $groupIdentifier): array;
}
