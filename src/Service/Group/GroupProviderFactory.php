<?php

declare(strict_types=1);

namespace App\Service\Group;

use Psr\Container\ContainerInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

class GroupProviderFactory implements ServiceSubscriberInterface
{
    public function __construct(
        private readonly ContainerInterface $locator,
        private readonly string $providerType,
    ) {}

    public function getProvider(): GroupProviderInterface
    {
        $serviceId = match (strtolower($this->providerType)) {
            'ldap' => LdapGroupProvider::class,
            'in_memory', 'inmemory' => InMemoryGroupProvider::class,
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown group provider type "%s"',
                $this->providerType,
            )),
        };

        return $this->locator->get($serviceId);
    }

    public static function getSubscribedServices(): array
    {
        return [
            LdapGroupProvider::class,
            InMemoryGroupProvider::class,
        ];
    }
}
