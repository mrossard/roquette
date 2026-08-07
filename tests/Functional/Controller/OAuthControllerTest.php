<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Controller\OAuthController;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

#[AllowMockObjectsWithoutExpectations]
class OAuthControllerTest extends WebTestCase
{
    private $client;
    private string $mockStorePath;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();
        $projectDir = $container->getParameter('kernel.project_dir');
        $this->mockStorePath = $projectDir . '/var/oauth_mock_store.json';
        $this->cleanupMockStore();
    }

    protected function tearDown(): void
    {
        $this->cleanupMockStore();
        parent::tearDown();
    }

    private function cleanupMockStore(): void
    {
        if (file_exists($this->mockStorePath)) {
            unlink($this->mockStorePath);
        }
    }

    /**
     * Generate a PKCE code_verifier and its S256 code_challenge.
     *
     * @return array{verifier: string, challenge: string}
     */
    private function generatePkcePair(): array
    {
        $verifier = OAuthController::generateCodeVerifier();
        $challenge = OAuthController::computeCodeChallenge($verifier);

        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    /**
     * Build the authorize URL with PKCE query params.
     */
    private function authorizeUrl(string $base, string $challenge): string
    {
        return $base
        . '?'
        . http_build_query([
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Complete an authorization + token exchange with PKCE, returning the access token.
     */
    private function completeMockOAuthFlow(string $username = 'pke_test_user'): string
    {
        $pkce = $this->generatePkcePair();

        $this->client->request('POST', $this->authorizeUrl('/oauth/mock/authorize', $pkce['challenge']), [
            'username' => $username,
            'redirect_uri' => 'http://127.0.0.1:8000/oauth/check',
            'state' => 'state_' . $username,
        ]);

        $location = $this->client->getResponse()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $code = $params['code'];

        $this->client->request('POST', '/oauth/mock/token', [
            'code' => $code,
            'code_verifier' => $pkce['verifier'],
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);

        return $data['access_token'];
    }

    // -------------------------------------------------------------------------
    // Mock OAuth flow
    // -------------------------------------------------------------------------

    #[Test]
    public function testMockAuthorizeFormRenders(): void
    {
        $this->client->request('GET', '/oauth/mock/authorize', [
            'client_id' => 'mock_client_id',
            'redirect_uri' => 'http://127.0.0.1:8000/oauth/check',
            'state' => 'test_state_123',
        ]);

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Mock OAuth2', $content);
        static::assertStringContainsString('test_state_123', $content);
        static::assertStringContainsString('127.0.0.1:8000', $content);
    }

    #[Test]
    public function testMockAuthorizePostReturnsRedirectWithCode(): void
    {
        $pkce = $this->generatePkcePair();

        $this->client->request('POST', $this->authorizeUrl('/oauth/mock/authorize', $pkce['challenge']), [
            'username' => 'test_oauth_user',
            'redirect_uri' => 'http://127.0.0.1:8000/oauth/check',
            'state' => 'test_state_456',
        ]);

        $this->assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        static::assertStringContainsString('code=mock_code_', $location);
        static::assertStringContainsString('state=test_state_456', $location);
    }

    #[Test]
    public function testMockAuthorizePostWithEmptyUsername(): void
    {
        $pkce = $this->generatePkcePair();

        $this->client->request('POST', $this->authorizeUrl('/oauth/mock/authorize', $pkce['challenge']), [
            'username' => '',
            'redirect_uri' => 'http://127.0.0.1:8000/oauth/check',
            'state' => 'test_state',
        ]);

        $this->assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        static::assertStringContainsString('code=mock_code_', $location);
    }

    #[Test]
    public function testMockTokenSuccess(): void
    {
        $accessToken = $this->completeMockOAuthFlow('token_user');

        static::assertNotEmpty($accessToken);
    }

    #[Test]
    public function testMockTokenInvalidCode(): void
    {
        $this->client->request('POST', '/oauth/mock/token', [
            'code' => 'invalid_code',
        ]);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        static::assertSame('invalid_grant', $data['error']);
    }

    #[Test]
    public function testMockTokenCodeIsOneTimeUse(): void
    {
        $pkce = $this->generatePkcePair();

        $this->client->request('POST', $this->authorizeUrl('/oauth/mock/authorize', $pkce['challenge']), [
            'username' => 'onetime_user',
            'redirect_uri' => 'http://127.0.0.1:8000/oauth/check',
            'state' => 'state_onetime',
        ]);

        $location = $this->client->getResponse()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $code = $params['code'];

        // First use - should succeed
        $this->client->request('POST', '/oauth/mock/token', [
            'code' => $code,
            'code_verifier' => $pkce['verifier'],
        ]);
        $this->assertResponseIsSuccessful();

        // Second use - should fail (one-time code)
        $this->client->request('POST', '/oauth/mock/token', [
            'code' => $code,
            'code_verifier' => $pkce['verifier'],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function testMockTokenMissingCodeVerifier(): void
    {
        $pkce = $this->generatePkcePair();

        $this->client->request('POST', $this->authorizeUrl('/oauth/mock/authorize', $pkce['challenge']), [
            'username' => 'missing_verifier',
            'redirect_uri' => 'http://127.0.0.1:8000/oauth/check',
            'state' => 'state_missing',
        ]);

        $location = $this->client->getResponse()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $code = $params['code'];

        // Exchange code without code_verifier -> should fail
        $this->client->request('POST', '/oauth/mock/token', [
            'code' => $code,
        ]);
        $this->assertResponseStatusCodeSame(400);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        static::assertSame('invalid_grant', $data['error']);
    }

    #[Test]
    public function testMockTokenInvalidCodeVerifier(): void
    {
        $pkce = $this->generatePkcePair();

        $this->client->request('POST', $this->authorizeUrl('/oauth/mock/authorize', $pkce['challenge']), [
            'username' => 'invalid_verifier',
            'redirect_uri' => 'http://127.0.0.1:8000/oauth/check',
            'state' => 'state_invalid',
        ]);

        $location = $this->client->getResponse()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $code = $params['code'];

        // Exchange code with a different verifier -> should fail
        $wrongVerifier = OAuthController::generateCodeVerifier();
        $this->client->request('POST', '/oauth/mock/token', [
            'code' => $code,
            'code_verifier' => $wrongVerifier,
        ]);
        $this->assertResponseStatusCodeSame(400);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        static::assertSame('invalid_grant', $data['error']);
    }

    #[Test]
    public function testMockUserSuccess(): void
    {
        $accessToken = $this->completeMockOAuthFlow('userinfo_test');

        // Get user info
        $this->client->request('GET', '/oauth/mock/user', [
            'access_token' => $accessToken,
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');
        $userData = json_decode($this->client->getResponse()->getContent(), true);
        static::assertSame('mock_id_userinfotest', $userData['id']);
        static::assertSame('userinfo_test', $userData['username']);
        static::assertSame('userinfo_test@example.com', $userData['email']);
    }

    #[Test]
    public function testMockUserInvalidToken(): void
    {
        $this->client->request('GET', '/oauth/mock/user', [
            'access_token' => 'invalid_token',
        ]);

        $this->assertResponseStatusCodeSame(401);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        static::assertSame('invalid_token', $data['error']);
    }

    #[Test]
    public function testMockUserWithBearerHeader(): void
    {
        $accessToken = $this->completeMockOAuthFlow('bearer_user');

        // Use Bearer header
        $this->client->request('GET', '/oauth/mock/user', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken]);

        $this->assertResponseIsSuccessful();
        $userData = json_decode($this->client->getResponse()->getContent(), true);
        static::assertSame('bearer_user', $userData['username']);
    }

    #[Test]
    public function testMockTokenWithJsonBody(): void
    {
        $pkce = $this->generatePkcePair();

        $this->client->request('POST', $this->authorizeUrl('/oauth/mock/authorize', $pkce['challenge']), [
            'username' => 'json_user',
            'redirect_uri' => 'http://127.0.0.1:8000/oauth/check',
            'state' => 'state_json',
        ]);

        $location = $this->client->getResponse()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $code = $params['code'];

        // Exchange with JSON body
        $this->client->request(
            'POST',
            '/oauth/mock/token',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['code' => $code, 'code_verifier' => $pkce['verifier']]),
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        static::assertArrayHasKey('access_token', $data);
    }

    #[Test]
    public function testMockTokenCodeViaQueryParam(): void
    {
        $pkce = $this->generatePkcePair();

        $this->client->request('POST', $this->authorizeUrl('/oauth/mock/authorize', $pkce['challenge']), [
            'username' => 'query_user',
            'redirect_uri' => 'http://127.0.0.1:8000/oauth/check',
            'state' => 'state_query',
        ]);

        $location = $this->client->getResponse()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $code = $params['code'];

        // Exchange with code and code_verifier as query params
        $this->client->request(
            'POST',
            '/oauth/mock/token?'
                . http_build_query([
                    'code' => $code,
                    'code_verifier' => $pkce['verifier'],
                ]),
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        static::assertArrayHasKey('access_token', $data);
    }

    #[Test]
    public function testMockTokenCodeViaQueryParamMissingVerifier(): void
    {
        $pkce = $this->generatePkcePair();

        $this->client->request('POST', $this->authorizeUrl('/oauth/mock/authorize', $pkce['challenge']), [
            'username' => 'query_missing',
            'redirect_uri' => 'http://127.0.0.1:8000/oauth/check',
            'state' => 'state_query_missing',
        ]);

        $location = $this->client->getResponse()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $code = $params['code'];

        // Exchange with code as query param but no verifier -> should fail
        $this->client->request('POST', '/oauth/mock/token?' . http_build_query(['code' => $code]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        static::assertSame('invalid_grant', $data['error']);
    }

    // -------------------------------------------------------------------------
    // Check route
    // -------------------------------------------------------------------------

    #[Test]
    public function testCheckThrowsLogicException(): void
    {
        $this->client->catchExceptions(false);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cette méthode est interceptée par l\'authentificateur OAuth2.');

        $this->client->request('GET', '/oauth/check');
    }

    #[Test]
    public function testMockEndpointsAreDisabledInProduction(): void
    {
        $controller = new OAuthController(
            'client_id',
            'auth_url',
            'redirect_uri',
            'scope',
            $this->mockStorePath,
            true,
            'prod',
        );

        $request = new Request();

        // 1. mockAuthorize
        try {
            $controller->mockAuthorize($request);
            static::fail('Expected NotFoundHttpException to be thrown for mockAuthorize in prod environment.');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            static::assertSame('Cette route n\'est pas disponible en production.', $e->getMessage());
        }

        // 2. mockToken
        try {
            $controller->mockToken($request);
            static::fail('Expected NotFoundHttpException to be thrown for mockToken in prod environment.');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            static::assertSame('Cette route n\'est pas disponible en production.', $e->getMessage());
        }

        // 3. mockUser
        try {
            $controller->mockUser($request);
            static::fail('Expected NotFoundHttpException to be thrown for mockUser in prod environment.');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            static::assertSame('Cette route n\'est pas disponible en production.', $e->getMessage());
        }
    }
}
