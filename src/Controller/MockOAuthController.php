<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\OAuth\PkceUtil;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Local mock OAuth2 provider for demo and automated testing environments.
 */
final class MockOAuthController extends AbstractController
{
    private string $mockStorePath;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/oauth_mock_store.json')]
        string $mockStorePath,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        $this->mockStorePath = $mockStorePath;
    }

    #[Route('/oauth/mock/authorize', name: 'app_oauth_mock_authorize', methods: ['GET', 'POST'])]
    public function mockAuthorize(Request $request): Response
    {
        if ($this->environment === 'prod') {
            throw new NotFoundHttpException('Cette route n\'est pas disponible en production.');
        }

        $clientId = $request->query->get('client_id');
        $redirectUri = $request->query->get('redirect_uri');
        $state = $request->query->get('state');

        if ($request->isMethod('POST')) {
            $username = trim($request->request->get('username', ''));
            $redirectUri = $request->request->get('redirect_uri');
            $state = $request->request->get('state');

            if ($username === '') {
                $username = 'oauth_user';
            }

            // Generate an authorization code
            $code = 'mock_code_' . bin2hex(random_bytes(8));
            $oauthId = 'mock_id_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $username));

            // Collect PKCE challenge if present
            $codeChallenge = $request->query->get('code_challenge');
            $codeChallengeMethod = $request->query->get('code_challenge_method');

            // Save to mock store
            $store = $this->readMockStore();
            $store['codes'][$code] = [
                'username' => $username,
                'oauth_id' => $oauthId,
                'email' => $username . '@example.com',
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => $codeChallengeMethod,
            ];
            $this->writeMockStore($store);

            // Redirect back to client app with code and state
            $url = $redirectUri;
            $queryParams = http_build_query([
                'code' => $code,
                'state' => $state,
            ]);

            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . $queryParams;

            return new RedirectResponse($url);
        }

        return $this->render('oauth/mock_authorize.html.twig', [
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    #[Route('/oauth/mock/token', name: 'app_oauth_mock_token', methods: ['POST'])]
    public function mockToken(Request $request): JsonResponse
    {
        if ($this->environment === 'prod') {
            throw new NotFoundHttpException('Cette route n\'est pas disponible en production.');
        }

        $code = $request->request->get('code') ?? $request->query->get('code');
        $codeVerifier = $request->request->get('code_verifier') ?? $request->query->get('code_verifier');

        // Sometimes the request comes as JSON body
        if (!$code) {
            $content = json_decode($request->getContent(), true);
            $code = $content['code'] ?? null;
            $codeVerifier ??= $content['code_verifier'] ?? null;
        }

        $store = $this->readMockStore();

        $codeData = $store['codes'][$code] ?? null;
        if (!$code || $codeData === null) {
            return new JsonResponse([
                'error' => 'invalid_grant',
                'error_description' => 'Code d\'autorisation invalide.',
            ], 400);
        }

        // Validate PKCE code_verifier if code_challenge was stored
        if ($codeData['code_challenge'] !== null) {
            if ($codeVerifier === null) {
                return new JsonResponse([
                    'error' => 'invalid_grant',
                    'error_description' => 'PKCE code_verifier manquant.',
                ], 400);
            }

            $expectedChallenge = PkceUtil::computeCodeChallenge($codeVerifier);

            if (!hash_equals($codeData['code_challenge'], $expectedChallenge)) {
                return new JsonResponse([
                    'error' => 'invalid_grant',
                    'error_description' => 'PKCE code_verifier invalide.',
                ], 400);
            }
        }

        // Get user details associated with this code
        $userData = $store['codes'][$code];
        unset($store['codes'][$code]); // One-time use code

        // Create an access token
        $accessToken = 'mock_token_' . bin2hex(random_bytes(16));
        $store['tokens'][$accessToken] = $userData;
        $this->writeMockStore($store);

        return new JsonResponse([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);
    }

    #[Route('/oauth/mock/user', name: 'app_oauth_mock_user', methods: ['GET'])]
    public function mockUser(Request $request): JsonResponse
    {
        if ($this->environment === 'prod') {
            throw new NotFoundHttpException('Cette route n\'est pas disponible en production.');
        }

        $authHeader = $request->headers->get('Authorization');
        $accessToken = null;

        if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $accessToken = $matches[1];
        }

        if ($accessToken === null) {
            $accessToken = $request->query->get('access_token');
        }

        $store = $this->readMockStore();

        $tokenData = $store['tokens'][$accessToken] ?? null;
        if (!$accessToken || $tokenData === null) {
            return new JsonResponse([
                'error' => 'invalid_token',
                'error_description' => 'Jeton d\'accès invalide ou expiré.',
            ], 401);
        }

        $userData = $store['tokens'][$accessToken];

        return new JsonResponse([
            'id' => $userData['oauth_id'],
            'username' => $userData['username'],
            'displayname' => $userData['username'],
            'email' => $userData['email'],
        ]);
    }

    private function readMockStore(): array
    {
        if (!file_exists($this->mockStorePath)) {
            return ['codes' => [], 'tokens' => []];
        }

        $data = json_decode(file_get_contents($this->mockStorePath), true);
        return is_array($data) ? $data : ['codes' => [], 'tokens' => []];
    }

    private function writeMockStore(array $data): void
    {
        $dir = dirname($this->mockStorePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents($this->mockStorePath, json_encode($data, JSON_PRETTY_PRINT));
    }
}
