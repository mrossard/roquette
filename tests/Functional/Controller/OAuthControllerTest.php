<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
        $this->client->request('POST', '/oauth/mock/authorize', [
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
        $this->client->request('POST', '/oauth/mock/authorize', [
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
        // First, get a code via the authorize endpoint
        $this->client->request('POST', '/oauth/mock/authorize', [
            'username' => 'token_user',
            'redirect_uri' => 'http://127.0.0.1:8000/oauth/check',
            'state' => 'state_token',
        ]);

        $location = $this->client->getResponse()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $code = $params['code'];

        // Exchange code for token
        $this->client->request('POST', '/oauth/mock/token', [
            'code' => $code,
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');
        $data = json_decode($this->client->getResponse()->getContent(), true);
        static::assertArrayHasKey('access_token', $data);
        static::assertSame('Bearer', $data['token_type']);
        static::assertArrayHasKey('expires_in', $data);
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
        $this->client->request('POST', '/oauth/mock/authorize', [
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
        ]);
        $this->assertResponseIsSuccessful();

        // Second use - should fail (one-time code)
        $this->client->request('POST', '/oauth/mock/token', [
            'code' => $code,
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function testMockUserSuccess(): void
    {
        // Get a token first
        $this->client->request('POST', '/oauth/mock/authorize', [
            'username' => 'userinfo_test',
            'redirect_uri' => 'http://127.0.0.1:8000/oauth/check',
            'state' => 'state_user',
        ]);

        $location = $this->client->getResponse()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $code = $params['code'];

        $this->client->request('POST', '/oauth/mock/token', [
            'code' => $code,
        ]);
        $tokenData = json_decode($this->client->getResponse()->getContent(), true);
        $accessToken = $tokenData['access_token'];

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
        // Get a token
        $this->client->request('POST', '/oauth/mock/authorize', [
            'username' => 'bearer_user',
            'redirect_uri' => 'http://127.0.0.1:8000/oauth/check',
            'state' => 'state_bearer',
        ]);

        $location = $this->client->getResponse()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $code = $params['code'];

        $this->client->request('POST', '/oauth/mock/token', [
            'code' => $code,
        ]);
        $tokenData = json_decode($this->client->getResponse()->getContent(), true);
        $accessToken = $tokenData['access_token'];

        // Use Bearer header
        $this->client->request('GET', '/oauth/mock/user', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken]);

        $this->assertResponseIsSuccessful();
        $userData = json_decode($this->client->getResponse()->getContent(), true);
        static::assertSame('bearer_user', $userData['username']);
    }

    #[Test]
    public function testMockTokenWithJsonBody(): void
    {
        // Get a code
        $this->client->request('POST', '/oauth/mock/authorize', [
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
            json_encode(['code' => $code]),
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        static::assertArrayHasKey('access_token', $data);
    }

    #[Test]
    public function testMockTokenCodeViaQueryParam(): void
    {
        // Get a code
        $this->client->request('POST', '/oauth/mock/authorize', [
            'username' => 'query_user',
            'redirect_uri' => 'http://127.0.0.1:8000/oauth/check',
            'state' => 'state_query',
        ]);

        $location = $this->client->getResponse()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $code = $params['code'];

        // Exchange with query parameter
        $this->client->request('POST', '/oauth/mock/token?code=' . urlencode($code));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        static::assertArrayHasKey('access_token', $data);
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
        $controller = new \App\Controller\OAuthController(
            'client_id',
            'auth_url',
            'redirect_uri',
            'scope',
            $this->mockStorePath,
            true,
            'prod',
        );

        $request = new \Symfony\Component\HttpFoundation\Request();

        // 1. mockAuthorize
        try {
            $controller->mockAuthorize($request);
            $this->fail('Expected NotFoundHttpException to be thrown for mockAuthorize in prod environment.');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            $this->assertSame('Cette route n\'est pas disponible en production.', $e->getMessage());
        }

        // 2. mockToken
        try {
            $controller->mockToken($request);
            $this->fail('Expected NotFoundHttpException to be thrown for mockToken in prod environment.');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            $this->assertSame('Cette route n\'est pas disponible en production.', $e->getMessage());
        }

        // 3. mockUser
        try {
            $controller->mockUser($request);
            $this->fail('Expected NotFoundHttpException to be thrown for mockUser in prod environment.');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            $this->assertSame('Cette route n\'est pas disponible en production.', $e->getMessage());
        }
    }
}
