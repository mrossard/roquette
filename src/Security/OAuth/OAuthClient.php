<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OAuthClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'OAUTH_CLIENT_ID')]
        private readonly string $clientId,
        #[SensitiveParameter]
        #[Autowire(env: 'OAUTH_CLIENT_SECRET')]
        private readonly string $clientSecret,
        #[Autowire(env: 'OAUTH_TOKEN_URL')]
        private readonly string $tokenUrl,
        #[Autowire(env: 'OAUTH_USER_INFO_URL')]
        private readonly string $userInfoUrl,
        #[Autowire(env: 'OAUTH_REDIRECT_URI')]
        private readonly string $redirectUri,
    ) {}

    public function fetchAccessToken(string $code, ?string $codeVerifier): string
    {
        $redirectUri = $this->redirectUri !== ''
            ? $this->redirectUri
            : $this->urlGenerator->generate('app_oauth_check', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $tokenBody = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];

        if ($codeVerifier !== null) {
            $tokenBody['code_verifier'] = $codeVerifier;
        }

        try {
            $response = $this->httpClient->request('POST', $this->tokenUrl, [
                'body' => $tokenBody,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
            $data = $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to retrieve access token from OAuth2 server: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new CustomUserMessageAuthenticationException(
                'Impossible de récupérer le jeton d\'accès depuis le serveur OAuth2.',
            );
        }

        $accessToken = $data['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            $this->logger->error('OAuth2 server response did not contain an access token.');
            throw new CustomUserMessageAuthenticationException(
                'Le serveur OAuth2 n\'a pas retourné de jeton d\'accès.',
            );
        }

        return $accessToken;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchUserInfo(#[SensitiveParameter] string $accessToken): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->userInfoUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                ],
            ]);
            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to retrieve user info from OAuth2 server: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new CustomUserMessageAuthenticationException(
                'Impossible de récupérer les informations de l\'utilisateur.',
            );
        }
    }
}
