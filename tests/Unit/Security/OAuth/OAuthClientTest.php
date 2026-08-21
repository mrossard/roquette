<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\OAuth;

use App\Security\OAuth\OAuthClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[AllowMockObjectsWithoutExpectations]
class OAuthClientTest extends TestCase
{
    private HttpClientInterface $httpClient;
    private UrlGeneratorInterface $urlGenerator;
    private LoggerInterface $logger;
    private OAuthClient $client;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->client = new OAuthClient(
            $this->httpClient,
            $this->urlGenerator,
            $this->logger,
            'my_client_id',
            'my_client_secret',
            'https://auth.example.com/token',
            'https://auth.example.com/userinfo',
            'https://app.example.com/oauth/check',
        );
    }

    public function testFetchAccessTokenSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn(['access_token' => 'test_token_123']);

        $this->httpClient
            ->expects(static::once())
            ->method('request')
            ->with(
                'POST',
                'https://auth.example.com/token',
                static::callback(
                    static fn(array $options): bool => (
                        ($options['body']['code'] ?? null) === 'auth_code'
                        && ($options['body']['code_verifier'] ?? null) === 'verifier_abc'
                        && ($options['body']['client_id'] ?? null) === 'my_client_id'
                    ),
                ),
            )
            ->willReturn($response);

        $token = $this->client->fetchAccessToken('auth_code', 'verifier_abc');
        static::assertSame('test_token_123', $token);
    }

    public function testFetchAccessTokenThrowsWhenServerReturnsNoToken(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([]);

        $this->httpClient->method('request')->willReturn($response);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Le serveur OAuth2 n\'a pas retourné de jeton d\'accès.');

        $this->client->fetchAccessToken('auth_code', null);
    }

    public function testFetchAccessTokenThrowsOnHttpException(): void
    {
        $this->httpClient->method('request')->willThrowException(new \RuntimeException('Connection failed'));

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Impossible de récupérer le jeton d\'accès depuis le serveur OAuth2.');

        $this->client->fetchAccessToken('auth_code', null);
    }

    public function testFetchUserInfoSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn(['id' => '123', 'username' => 'alice']);

        $this->httpClient
            ->expects(static::once())
            ->method('request')
            ->with(
                'GET',
                'https://auth.example.com/userinfo',
                static::callback(static fn(array $options): bool => hash_equals(
                    'Bearer my_token',
                    (string) ($options['headers']['Authorization'] ?? ''),
                )),
            )
            ->willReturn($response);

        $data = $this->client->fetchUserInfo('my_token');
        static::assertSame(['id' => '123', 'username' => 'alice'], $data);
    }

    public function testFetchUserInfoThrowsOnHttpException(): void
    {
        $this->httpClient->method('request')->willThrowException(new \RuntimeException('Network down'));

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Impossible de récupérer les informations de l\'utilisateur.');

        $this->client->fetchUserInfo('my_token');
    }
}
