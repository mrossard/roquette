<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class OAuthController extends AbstractController
{
    private string $mockStorePath;

    public function __construct(
        #[Autowire(env: 'OAUTH_CLIENT_ID')]
        private string $clientId,
        #[Autowire(env: 'OAUTH_AUTH_URL')]
        private string $authUrl,
        #[Autowire(env: 'OAUTH_REDIRECT_URI')]
        private string $redirectUri,
        #[Autowire(env: 'OAUTH_SCOPE')]
        private string $scope,
        #[Autowire('%kernel.project_dir%/var/oauth_mock_store.json')]
        string $mockStorePath,
        #[Autowire(env: 'bool:AUTH_OAUTH_ENABLED')]
        private bool $authOauthEnabled,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
        $this->mockStorePath = $mockStorePath;
    }

    #[Route('/oauth/connect', name: 'app_oauth_connect')]
    public function connect(Request $request): Response
    {
        if (!$this->authOauthEnabled) {
            throw $this->createAccessDeniedException('L\'authentification OAuth2 est désactivée.');
        }

        $state = bin2hex(random_bytes(16));
        $request->getSession()->set('oauth2state', $state);

        $redirectUri =
            $this->redirectUri !== null && $this->redirectUri !== ''
                ? $this->redirectUri
                : $this->generateUrl('app_oauth_check', [], UrlGeneratorInterface::ABSOLUTE_URL);

        // Build redirect URL
        $queryParams = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
            'scope' => $this->scope,
        ]);

        $url = $this->authUrl;
        if (str_contains($url, '?')) {
            $url .= '&' . $queryParams;
        } else {
            $url .= '?' . $queryParams;
        }

        return new RedirectResponse($url);
    }

    #[Route('/oauth/check', name: 'app_oauth_check')]
    public function check(): void
    {
        throw new \LogicException('Cette méthode est interceptée par l\'authentificateur OAuth2.');
    }

    /*
     * ==========================================
     * LOCAL MOCK OAUTH2 PROVIDER
     * For testing/demo out-of-the-box
     * ==========================================
     */

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

            // Save to mock store
            $store = $this->readMockStore();
            $store['codes'][$code] = [
                'username' => $username,
                'oauth_id' => $oauthId,
                'email' => $username . '@example.com',
            ];
            $this->writeMockStore($store);

            // Redirect back to client app with code and state
            $url = $redirectUri;
            $queryParams = http_build_query([
                'code' => $code,
                'state' => $state,
            ]);

            if (str_contains($url, '?')) {
                $url .= '&' . $queryParams;
            } else {
                $url .= '?' . $queryParams;
            }

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

        // Sometimes the request comes as JSON body
        if (!$code) {
            $content = json_decode($request->getContent(), true);
            $code = $content['code'] ?? null;
        }

        $store = $this->readMockStore();

        $codeData = $store['codes'][$code] ?? null;
        if (!$code || $codeData === null) {
            return new JsonResponse([
                'error' => 'invalid_grant',
                'error_description' => 'Code d\'autorisation invalide.',
            ], 400);
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
        } else {
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
            'email' => $userData['email'],
        ]);
    }

    /*
     * ==========================================
     * HELPER METHODS FOR FILE STORE
     * ==========================================
     */

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
