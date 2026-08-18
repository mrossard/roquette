<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\RobotUserProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OAuth2Authenticator extends AbstractAuthenticator
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private UrlGeneratorInterface $urlGenerator,
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private LoggerInterface $logger,
        #[Autowire(env: 'OAUTH_CLIENT_ID')]
        private string $clientId,
        #[\SensitiveParameter]
        #[Autowire(env: 'OAUTH_CLIENT_SECRET')]
        private string $clientSecret,
        #[Autowire(env: 'OAUTH_AUTH_URL')]
        private string $authUrl,
        #[Autowire(env: 'OAUTH_TOKEN_URL')]
        private string $tokenUrl,
        #[Autowire(env: 'OAUTH_USER_INFO_URL')]
        private string $userInfoUrl,
        #[Autowire(env: 'OAUTH_USERNAME_FIELD')]
        private string $usernameField,
        #[Autowire(env: 'OAUTH_REDIRECT_URI')]
        private string $redirectUri,
        #[Autowire(env: 'OAUTH_DISPLAY_NAME_FIELD')]
        private string $displayNameField,
        #[Autowire(env: 'bool:AUTH_OAUTH_ENABLED')]
        private bool $authOauthEnabled,
        private RobotUserProvider $robotUserProvider,
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
        $accessToken = $this->fetchAccessToken($code, $codeVerifier);
        $userData = $this->fetchUserInfo($accessToken);
        $attributes = $this->extractUserAttributes($userData);

        $user = $this->findOrCreateUser(
            $attributes['oauthId'],
            $attributes['username'],
            $attributes['displayName'],
            $attributes['email'],
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

    private function fetchAccessToken(string $code, ?string $codeVerifier): string
    {
        $redirectUri =
            $this->redirectUri !== null && $this->redirectUri !== ''
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
        } catch (\Exception $e) {
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
    private function fetchUserInfo(#[\SensitiveParameter] string $accessToken): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->userInfoUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                ],
            ]);
            return $response->toArray();
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve user info from OAuth2 server: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new CustomUserMessageAuthenticationException(
                'Impossible de récupérer les informations de l\'utilisateur.',
            );
        }
    }

    /**
     * @param array<string, mixed> $userData
     * @return array{oauthId: string, username: string, displayName: string, email: ?string}
     */
    private function extractUserAttributes(array $userData): array
    {
        $oauthId = (string) ($userData['id'] ?? $userData['sub'] ?? $userData[$this->usernameField] ?? null);
        $username = (string) (
            $userData[$this->usernameField] ?? $userData['username'] ?? $userData['email'] ?? $userData['login'] ?? null
        );
        $displayName = is_string($userData[$this->displayNameField] ?? null)
            ? $userData[$this->displayNameField]
            : $username;
        $email = is_string($userData['mail'] ?? null) ? $userData['mail'] : null;

        if ($oauthId === '' || $username === '') {
            $this->logger->error('Incomplete user info returned by OAuth2 server.', ['userData' => $userData]);
            throw new CustomUserMessageAuthenticationException(
                'Les informations utilisateur retournées par le serveur OAuth2 sont incomplètes.',
            );
        }

        if ($this->robotUserProvider->isRobotUsername($username)) {
            throw new CustomUserMessageAuthenticationException('Connexion impossible avec un compte système.');
        }

        return [
            'oauthId' => $oauthId,
            'username' => $username,
            'displayName' => $displayName,
            'email' => $email,
        ];
    }

    private function findOrCreateUser(string $oauthId, string $username, string $displayName, ?string $email): User
    {
        // 1. Search by OAuth ID and provider
        $user = $this->userRepository->findOneBy([
            'oauthId' => $oauthId,
            'oauthProvider' => 'generic',
        ]);

        if ($user !== null) {
            $this->assertUserNotBanned($user);
            $this->syncUserEmailIfMissing($user, $email);
            $this->logger->debug(sprintf(
                'User "%s" authenticated via OAuth2 with provider ID "%s".',
                $user->getUsername(),
                $oauthId,
            ));

            return $user;
        }

        // 2. Search by username to link account
        $existingUserByUsername = $this->userRepository->findOneBy(['username' => $username]);
        if ($existingUserByUsername !== null) {
            return $this->linkExistingUser($existingUserByUsername, $oauthId, $username, $email);
        }

        // 3. Create a brand new user
        return $this->registerNewOAuthUser($oauthId, $username, $displayName, $email);
    }

    private function assertUserNotBanned(User $user): void
    {
        if ($user->isBanned()) {
            throw new CustomUserMessageAuthenticationException(
                'Votre compte a été suspendu. Veuillez contacter un administrateur.',
            );
        }
    }

    private function syncUserEmailIfMissing(User $user, ?string $email): void
    {
        if ($email !== null && $user->getEmail() === null) {
            $user->setEmail($email);
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->logger->info(sprintf(
                'Filled missing email "%s" for OAuth user "%s" (ID: %d).',
                $email,
                $user->getUsername(),
                $user->getId(),
            ));
        }
    }

    private function linkExistingUser(User $existingUser, string $oauthId, string $username, ?string $email): User
    {
        if ($existingUser->getOauthId() !== null && $existingUser->getOauthId() !== $oauthId) {
            $this->logger->warning(sprintf(
                'Refused linking OAuth account for username "%s": existing OAuth ID "%s" does not match incoming "%s".',
                $username,
                $existingUser->getOauthId(),
                $oauthId,
            ));
            throw new CustomUserMessageAuthenticationException(
                'Ce nom d\'utilisateur est déjà lié à un autre compte OAuth.',
            );
        }

        $this->assertUserNotBanned($existingUser);

        if ($email !== null && $existingUser->getEmail() === null) {
            $existingUser->setEmail($email);
        }

        $existingUser->setOauthId($oauthId);
        $existingUser->setOauthProvider('generic');
        $this->entityManager->flush();
        $this->logger->info(sprintf(
            'Linked existing user "%s" (ID: %d) with OAuth2 ID "%s".',
            $username,
            $existingUser->getId(),
            $oauthId,
        ));

        return $existingUser;
    }

    private function registerNewOAuthUser(string $oauthId, string $username, string $displayName, ?string $email): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setDisplayName($displayName);
        $user->setOauthId($oauthId);
        $user->setOauthProvider('generic');

        if ($email !== null) {
            $user->setEmail($email);
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
        }

        $randomPassword = bin2hex(random_bytes(16));
        $user->setPassword($this->passwordHasher->hashPassword($user, $randomPassword));
        $user->setRoles(['ROLE_USER']);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->logger->info(sprintf(
            'Created new user "%s" (ID: %d) via OAuth2 registration with OAuth2 ID "%s".',
            $username,
            $user->getId(),
            $oauthId,
        ));

        return $user;
    }

    public function onAuthenticationSuccess(
        Request $request,
        #[\SensitiveParameter]
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
