<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\OAuth2Authenticator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[AllowMockObjectsWithoutExpectations]
class OAuth2AuthenticatorTest extends TestCase
{
    private HttpClientInterface $httpClient;
    private UrlGeneratorInterface $urlGenerator;
    private UserRepository $userRepository;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    private LoggerInterface $logger;
    private OAuth2Authenticator $authenticator;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->authenticator = new OAuth2Authenticator(
            $this->httpClient,
            $this->urlGenerator,
            $this->userRepository,
            $this->entityManager,
            $this->passwordHasher,
            $this->logger,
            'client_id',
            'client_secret',
            'http://oauth/auth',
            'http://oauth/token',
            'http://oauth/userinfo',
            'username',
            'http://redirect',
            'name',
            true,
        );
    }

    private function createMockRequest(string $state = 'test_state'): Request
    {
        $session = $this->createMock(SessionInterface::class);
        $session
            ->method('get')
            ->willReturnCallback(static function (string $name) use ($state) {
                if ($name === 'oauth2state') {
                    return $state;
                }
                return null;
            });

        $request = new Request(['code' => 'valid_code', 'state' => $state]);
        $request->setSession($session);

        return $request;
    }

    public function testAuthenticateThrowsExceptionWhenExistingUserHasDifferentOauthId(): void
    {
        $request = $this->createMockRequest();

        $tokenResponse = $this->createMock(ResponseInterface::class);
        $tokenResponse->method('toArray')->willReturn(['access_token' => 'token_123']);

        $userInfoResponse = $this->createMock(ResponseInterface::class);
        $userInfoResponse
            ->method('toArray')
            ->willReturn([
                'id' => 'new_oauth_id',
                'username' => 'john_doe',
            ]);

        $this->httpClient->method('request')->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        // 1. Search by OAuth ID -> not found
        // 2. Search by username -> found user with DIFFERENT oauthId
        $existingUser = new User();
        $existingUser->setUsername('john_doe');
        $existingUser->setOauthId('old_different_oauth_id');

        $this->userRepository
            ->method('findOneBy')
            ->willReturnCallback(static function (array $criteria) use ($existingUser) {
                if (($criteria['oauthId'] ?? null) === 'new_oauth_id') {
                    return null;
                }
                if (($criteria['username'] ?? null) === 'john_doe') {
                    return $existingUser;
                }
                return null;
            });

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Ce nom d\'utilisateur est déjà lié à un autre compte OAuth.');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateAllowsLinkingWhenExistingUserHasMatchingOauthId(): void
    {
        $request = $this->createMockRequest();

        $tokenResponse = $this->createMock(ResponseInterface::class);
        $tokenResponse->method('toArray')->willReturn(['access_token' => 'token_123']);

        $userInfoResponse = $this->createMock(ResponseInterface::class);
        $userInfoResponse
            ->method('toArray')
            ->willReturn([
                'id' => 'same_oauth_id',
                'username' => 'john_doe',
            ]);

        $this->httpClient->method('request')->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        $existingUser = new User();
        $existingUser->setUsername('john_doe');
        $existingUser->setOauthId('same_oauth_id');

        $this->userRepository
            ->method('findOneBy')
            ->willReturnCallback(static function (array $criteria) use ($existingUser) {
                if (($criteria['oauthId'] ?? null) === 'same_oauth_id') {
                    return $existingUser;
                }
                return null;
            });

        $passport = $this->authenticator->authenticate($request);
        static::assertSame($existingUser, $passport->getUser());
    }

    public function testAuthenticateAllowsLinkingWhenExistingUserHasNullOauthId(): void
    {
        $request = $this->createMockRequest();

        $tokenResponse = $this->createMock(ResponseInterface::class);
        $tokenResponse->method('toArray')->willReturn(['access_token' => 'token_123']);

        $userInfoResponse = $this->createMock(ResponseInterface::class);
        $userInfoResponse
            ->method('toArray')
            ->willReturn([
                'id' => 'new_oauth_id',
                'username' => 'john_doe',
            ]);

        $this->httpClient->method('request')->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        $existingUser = new User();
        $existingUser->setUsername('john_doe');
        $existingUser->setOauthId(null);

        $this->userRepository
            ->method('findOneBy')
            ->willReturnCallback(static function (array $criteria) use ($existingUser) {
                if (($criteria['oauthId'] ?? null) === 'new_oauth_id') {
                    return null;
                }
                if (($criteria['username'] ?? null) === 'john_doe') {
                    return $existingUser;
                }
                return null;
            });

        $this->entityManager->expects(static::once())->method('flush');

        $passport = $this->authenticator->authenticate($request);
        static::assertSame($existingUser, $passport->getUser());
        static::assertSame('new_oauth_id', $existingUser->getOauthId());
    }
}
