<?php

declare(strict_types=1);

namespace App\Security;

use App\Security\OAuth\OAuthClient;
use App\Security\OAuth\OAuthUserExtractor;
use App\Security\OAuth\OAuthUserManager;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

class OAuth2Authenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly OAuthClient $oauthClient,
        private readonly OAuthUserExtractor $oauthUserExtractor,
        private readonly OAuthUserManager $oauthUserManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'bool:AUTH_OAUTH_ENABLED')]
        private readonly bool $authOauthEnabled,
    ) {}

    public function supports(Request $request): ?bool
    {
        if (!$this->authOauthEnabled) {
            return false;
        }

        return $request->getPathInfo() === '/oauth/check' && $request->query->has('code');
    }

    public function authenticate(Request $request): Passport
    {
        $code = (string) $request->query->get('code');
        $codeVerifier = $this->validateCsrfStateAndGetCodeVerifier($request);
        $accessToken = $this->oauthClient->fetchAccessToken($code, $codeVerifier);
        $userData = $this->oauthClient->fetchUserInfo($accessToken);
        $attributes = $this->oauthUserExtractor->extract($userData);

        $user = $this->oauthUserManager->findOrCreateUser(
            $attributes->oauthId,
            $attributes->username,
            $attributes->displayName,
            $attributes->email,
        );

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn() => $user));
    }

    private function validateCsrfStateAndGetCodeVerifier(Request $request): ?string
    {
        $state = $request->query->get('state');
        $session = $request->getSession();
        $storedState = $session->get('oauth2state');

        if (!$state || !$storedState || !hash_equals($state, $storedState)) {
            $this->logger->warning('OAuth2 state validation failed. Possible CSRF attack.');
            throw new CustomUserMessageAuthenticationException(
                'La validation de l\'état de sécurité (CSRF) a échoué. Veuillez réessayer.',
            );
        }

        $session->remove('oauth2state');

        $codeVerifier = $session->get('oauth2code_verifier');
        $session->remove('oauth2code_verifier');

        return is_string($codeVerifier) ? $codeVerifier : null;
    }

    public function onAuthenticationSuccess(
        Request $request,
        #[SensitiveParameter]
        TokenInterface $token,
        string $firewallName,
    ): ?Response {
        $this->logger->info(sprintf('User "%s" successfully authenticated.', $token->getUserIdentifier()));
        return new RedirectResponse($this->urlGenerator->generate('app_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $this->logger->warning(sprintf('Authentication failure: %s', $exception->getMessageKey()), [
            'exception' => $exception,
        ]);
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);
        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
