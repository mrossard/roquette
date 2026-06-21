<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stub;

use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\RemoteHubInterface;
use Symfony\Component\Mercure\Update;

class HubStub implements RemoteHubInterface
{
    public function getUrl(): string
    {
        return 'http://localhost/.well-known/mercure';
    }

    public function getPublicUrl(): string
    {
        return 'http://localhost/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return new TokenFactoryStub();
    }

    public function publish(Update $update): string
    {
        return 'mocked-update-id';
    }
}
