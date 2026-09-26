<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stub;

use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;

class TokenFactoryStub implements TokenFactoryInterface
{
    public function create(array $grants = [], array $additionalClaims = []): string
    {
        return 'mocked-jwt-token';
    }
}
