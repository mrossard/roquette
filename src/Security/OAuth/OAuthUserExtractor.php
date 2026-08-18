<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Service\RobotUserProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

class OAuthUserExtractor
{
    public function __construct(
        private readonly RobotUserProvider $robotUserProvider,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'OAUTH_USERNAME_FIELD')]
        private readonly string $usernameField,
        #[Autowire(env: 'OAUTH_DISPLAY_NAME_FIELD')]
        private readonly string $displayNameField,
    ) {}

    /**
     * @param array<string, mixed> $userData
     */
    public function extract(array $userData): OAuthUserAttributes
    {
        $oauthId = (string) ($userData['id'] ?? $userData['sub'] ?? $userData[$this->usernameField] ?? null);
        $username = (string) (
            $userData[$this->usernameField] ?? $userData['username'] ?? $userData['email'] ?? $userData['login'] ?? null
        );
        $displayName = is_string($userData[$this->displayNameField] ?? null)
            ? $userData[$this->displayNameField]
            : $username;
        $email = is_string($userData['mail'] ?? null) ? $userData['mail'] : null;

        if ($oauthId === '' || $username === '') {
            $this->logger->error('Incomplete user info returned by OAuth2 server.', ['userData' => $userData]);
            throw new CustomUserMessageAuthenticationException(
                'Les informations utilisateur retournées par le serveur OAuth2 sont incomplètes.',
            );
        }

        if ($this->robotUserProvider->isRobotUsername($username)) {
            throw new CustomUserMessageAuthenticationException('Connexion impossible avec un compte système.');
        }

        return new OAuthUserAttributes(
            oauthId: $oauthId,
            username: $username,
            displayName: $displayName,
            email: $email,
        );
    }
}
