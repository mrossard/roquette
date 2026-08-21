<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\OAuth2Authenticator;
use App\Security\OAuth\OAuthClient;
use App\Security\OAuth\OAuthUserAttributes;
use App\Security\OAuth\OAuthUserExtractor;
use App\Security\OAuth\OAuthUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

#[AllowMockObjectsWithoutExpectations]
class OAuth2AuthenticatorTest extends TestCase
{
    private OAuthClient $oauthClient;
    private OAuthUserExtractor $oauthUserExtractor;
    private OAuthUserManager $oauthUserManager;
    private UrlGeneratorInterface $urlGenerator;
    private LoggerInterface $logger;
    private OAuth2Authenticator $authenticator;

    protected function setUp(): void
    {
        $this->oauthClient = $this->createMock(OAuthClient::class);
        $this->oauthUserExtractor = $this->createMock(OAuthUserExtractor::class);
        $this->oauthUserManager = $this->createMock(OAuthUserManager::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->authenticator = new OAuth2Authenticator(
            $this->oauthClient,
            $this->oauthUserExtractor,
            $this->oauthUserManager,
            $this->urlGenerator,
            $this->logger,
            true,
        );
    }

    private function createMockRequest(string $state = 'test_state', ?string $codeVerifier = 'verifier_123'): Request
    {
        $session = $this->createMock(SessionInterface::class);
        $session
            ->method('get')
            ->willReturnCallback(static function (string $name) use ($state, $codeVerifier) {
                if ($name === 'oauth2state') {
                    return $state;
                }
                if ($name === 'oauth2code_verifier') {
                    return $codeVerifier;
                }
                return null;
            });

        $request = new Request(['code' => 'valid_code', 'state' => $state]);
        $request->setSession($session);

        return $request;
    }

    public function testSupportsReturnsTrueWhenEnabledAndValidPath(): void
    {
        $request = Request::create('/oauth/check?code=123');
        static::assertTrue($this->authenticator->supports($request));
    }

    public function testSupportsReturnsFalseWhenDisabled(): void
    {
        $authenticator = new OAuth2Authenticator(
            $this->oauthClient,
            $this->oauthUserExtractor,
            $this->oauthUserManager,
            $this->urlGenerator,
            $this->logger,
            false,
        );

        $request = Request::create('/oauth/check?code=123');
        static::assertFalse($authenticator->supports($request));
    }

    public function testAuthenticateThrowsWhenStateMismatch(): void
    {
        $request = $this->createMockRequest('state_A');
        // change state in request to cause mismatch
        $request->query->set('state', 'state_B');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('La validation de l\'état de sécurité (CSRF) a échoué. Veuillez réessayer.');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateOrchestratesFlowSuccessfully(): void
    {
        $request = $this->createMockRequest();

        $this->oauthClient
            ->expects(static::once())
            ->method('fetchAccessToken')
            ->with('valid_code', 'verifier_123')
            ->willReturn('access_token_xyz');

        $userData = ['id' => '123', 'username' => 'alice'];
        $this->oauthClient
            ->expects(static::once())
            ->method('fetchUserInfo')
            ->with('access_token_xyz')
            ->willReturn($userData);

        $attributes = new OAuthUserAttributes(
            oauthId: '123',
            username: 'alice',
            displayName: 'Alice',
            email: 'alice@example.com',
        );
        $this->oauthUserExtractor->expects(static::once())->method('extract')->with($userData)->willReturn($attributes);

        $user = new User();
        $user->setUsername('alice');
        $this->oauthUserManager
            ->expects(static::once())
            ->method('findOrCreateUser')
            ->with('123', 'alice', 'Alice', 'alice@example.com')
            ->willReturn($user);

        $passport = $this->authenticator->authenticate($request);
        static::assertSame($user, $passport->getUser());
    }

    public function testOnAuthenticationSuccessRedirectsToDashboard(): void
    {
        $request = new Request();
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUserIdentifier')->willReturn('alice');

        $this->urlGenerator
            ->expects(static::once())
            ->method('generate')
            ->with('app_dashboard')
            ->willReturn('/dashboard');

        $response = $this->authenticator->onAuthenticationSuccess($request, $token, 'main');
        static::assertInstanceOf(RedirectResponse::class, $response);
        static::assertSame('/dashboard', $response->getTargetUrl());
    }

    public function testOnAuthenticationFailureRedirectsToLogin(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects(static::once())->method('set');

        $request = new Request();
        $request->setSession($session);

        $this->urlGenerator->expects(static::once())->method('generate')->with('app_login')->willReturn('/login');

        $response = $this->authenticator->onAuthenticationFailure($request, new AuthenticationException('Invalid'));
        static::assertInstanceOf(RedirectResponse::class, $response);
        static::assertSame('/login', $response->getTargetUrl());
    }
}
