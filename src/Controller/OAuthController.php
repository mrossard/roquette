<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\OAuth\PkceUtil;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Controller initiating client OAuth2 authorization flows.
 */
final class OAuthController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'OAUTH_CLIENT_ID')]
        private readonly string $clientId,
        #[Autowire(env: 'OAUTH_AUTH_URL')]
        private readonly string $authUrl,
        #[Autowire(env: 'OAUTH_REDIRECT_URI')]
        private readonly string $redirectUri,
        #[Autowire(env: 'OAUTH_SCOPE')]
        private readonly string $scope,
        #[Autowire(env: 'bool:AUTH_OAUTH_ENABLED')]
        private readonly bool $authOauthEnabled,
    ) {}

    #[Route('/oauth/connect', name: 'app_oauth_connect')]
    public function connect(Request $request): Response
    {
        if (!$this->authOauthEnabled) {
            throw $this->createAccessDeniedException('L\'authentification OAuth2 est désactivée.');
        }

        $state = bin2hex(random_bytes(16));
        $request->getSession()->set('oauth2state', $state);

        $codeVerifier = PkceUtil::generateCodeVerifier();
        $request->getSession()->set('oauth2code_verifier', $codeVerifier);

        $redirectUri =
            $this->redirectUri !== ''
                ? $this->redirectUri
                : $this->generateUrl('app_oauth_check', [], UrlGeneratorInterface::ABSOLUTE_URL);

        // Build redirect URL with PKCE
        $queryParams = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
            'scope' => $this->scope,
            'code_challenge' => PkceUtil::computeCodeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ]);

        $separator = str_contains($this->authUrl, '?') ? '&' : '?';
        $url = $this->authUrl . $separator . $queryParams;

        return new RedirectResponse($url);
    }

    #[Route('/oauth/check', name: 'app_oauth_check')]
    public function check(): void
    {
        throw new \LogicException('Cette méthode est interceptée par l\'authentificateur OAuth2.');
    }

    /**
     * Backward-compatible helper forwarding to PkceUtil.
     */
    public static function generateCodeVerifier(): string
    {
        return PkceUtil::generateCodeVerifier();
    }

    /**
     * Backward-compatible helper forwarding to PkceUtil.
     */
    public static function computeCodeChallenge(string $codeVerifier): string
    {
        return PkceUtil::computeCodeChallenge($codeVerifier);
    }
}
