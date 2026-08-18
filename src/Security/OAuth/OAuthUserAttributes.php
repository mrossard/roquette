<?php

declare(strict_types=1);

namespace App\Security\OAuth;

final readonly class OAuthUserAttributes
{
    public function __construct(
        public string $oauthId,
        public string $username,
        public string $displayName,
        public ?string $email,
    ) {}
}
