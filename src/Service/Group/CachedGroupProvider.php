<?php

declare(strict_types=1);

namespace App\Service\Group;

use App\Entity\User;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class CachedGroupProvider implements GroupProviderInterface
{
    public function __construct(
        private readonly GroupProviderInterface $delegate,
        private readonly CacheInterface $cache,
        private readonly int $ttl = 300, // 5 minutes cache
    ) {}

    public function getGroups(string $searchQuery = ''): array
    {
        $key = $searchQuery === '' ? 'group_provider_all_groups' : 'group_provider_search_groups_' . md5($searchQuery);
        return $this->cache->get($key, function (ItemInterface $item) use ($searchQuery) {
            $item->expiresAfter($this->ttl);
            return $this->delegate->getGroups($searchQuery);
        });
    }

    public function getGroupsForUser(User $user): array
    {
        $key = sprintf('group_provider_user_groups_%d', $user->getId());
        return $this->cache->get($key, function (ItemInterface $item) use ($user) {
            $item->expiresAfter($this->ttl);
            return $this->delegate->getGroupsForUser($user);
        });
    }

    public function getGroupByIdentifier(string $identifier): ?GroupDTO
    {
        $key = sprintf('group_provider_group_%s', md5($identifier));
        return $this->cache->get($key, function (ItemInterface $item) use ($identifier) {
            $item->expiresAfter($this->ttl);
            return $this->delegate->getGroupByIdentifier($identifier);
        });
    }

    public function getGroupMembers(string $groupIdentifier): array
    {
        $key = sprintf('group_provider_group_members_%s', md5($groupIdentifier));
        return $this->cache->get($key, function (ItemInterface $item) use ($groupIdentifier) {
            $item->expiresAfter($this->ttl);
            return $this->delegate->getGroupMembers($groupIdentifier);
        });
    }
}
