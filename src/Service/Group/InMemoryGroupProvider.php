<?php

declare(strict_types=1);

namespace App\Service\Group;

use App\Entity\User;

class InMemoryGroupProvider implements GroupProviderInterface
{
    /**
     * @var array<string, array{name: string, description: string, members: string[]}>
     */
    private array $groups = [
        'group-dev' => [
            'name' => 'Développeurs',
            'description' => 'Équipe de développement',
            'members' => ['manu', 'admin'],
        ],
        'group-hr' => [
            'name' => 'Ressources Humaines',
            'description' => 'Équipe des ressources humaines',
            'members' => ['hr_user'],
        ],
        'group-marketing' => [
            'name' => 'Marketing',
            'description' => 'Équipe marketing et communication',
            'members' => ['marketing_user'],
        ],
    ];

    public function getGroups(string $searchQuery = ''): array
    {
        $dtos = [];
        foreach ($this->groups as $identifier => $data) {
            if ($searchQuery !== '') {
                $q = strtolower($searchQuery);
                if (
                    !str_contains(strtolower($data['name']), $q)
                    && !str_contains(strtolower($data['description']), $q)
                    && !str_contains(strtolower($identifier), $q)
                ) {
                    continue;
                }
            }
            $dtos[] = new GroupDTO($identifier, $data['name'], $data['description']);
        }
        return $dtos;
    }

    public function getGroupsForUser(User $user): array
    {
        $dtos = [];
        foreach ($this->groups as $identifier => $data) {
            if (!in_array($user->getUsername(), $data['members'], true)) {
                continue;
            }
            $dtos[] = new GroupDTO($identifier, $data['name'], $data['description']);
        }
        return $dtos;
    }

    public function getGroupByIdentifier(string $identifier): ?GroupDTO
    {
        if (!\array_key_exists($identifier, $this->groups)) {
            return null;
        }
        $data = $this->groups[$identifier];
        return new GroupDTO($identifier, $data['name'], $data['description']);
    }

    public function getGroupMembers(string $groupIdentifier): array
    {
        if (!\array_key_exists($groupIdentifier, $this->groups)) {
            return [];
        }
        return $this->groups[$groupIdentifier]['members'];
    }
}
