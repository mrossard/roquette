<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
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
        #[Autowire(env: 'bool:AUTH_OAUTH_ENABLED')]
        private bool $authOauthEnabled,
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
        $code = $request->query->get('code');
        $state = $request->query->get('state');

        $session = $request->getSession();
        $storedState = $session->get('oauth2state');

        if (!$state || $state !== $storedState) {
            $this->logger->warning('OAuth2 state validation failed. Possible CSRF attack.');
            throw new CustomUserMessageAuthenticationException(
                'La validation de l\'état de sécurité (CSRF) a échoué. Veuillez réessayer.',
            );
        }

        $session->remove('oauth2state');

        $redirectUri =
            $this->redirectUri !== null && $this->redirectUri !== ''
                ? $this->redirectUri
                : $this->urlGenerator->generate('app_oauth_check', [], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $response = $this->httpClient->request('POST', $this->tokenUrl, [
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
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
        if (!$accessToken) {
            $this->logger->error('OAuth2 server response did not contain an access token.');
            throw new CustomUserMessageAuthenticationException(
                'Le serveur OAuth2 n\'a pas retourné de jeton d\'accès.',
            );
        }

        try {
            $response = $this->httpClient->request('GET', $this->userInfoUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                ],
            ]);
            $userData = $response->toArray();
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve user info from OAuth2 server: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new CustomUserMessageAuthenticationException(
                'Impossible de récupérer les informations de l\'utilisateur.',
            );
        }

        $oauthId = (string) ($userData['id'] ?? $userData['sub'] ?? $userData[$this->usernameField] ?? null);
        $username = (string) (
            $userData[$this->usernameField] ?? $userData['username'] ?? $userData['email'] ?? $userData['login'] ?? null
        );
        $email = is_string($userData['mail'] ?? null) ? $userData['mail'] : null;

        if (!$oauthId || !$username) {
            $this->logger->error('Incomplete user info returned by OAuth2 server.', ['userData' => $userData]);
            throw new CustomUserMessageAuthenticationException(
                'Les informations utilisateur retournées par le serveur OAuth2 sont incomplètes.',
            );
        }

        if (strcasecmp($username, User::ROBOT_USERNAME) === 0) {
            throw new CustomUserMessageAuthenticationException('Connexion impossible avec un compte système.');
        }

        // 1. Search by OAuth ID and provider
        $user = $this->userRepository->findOneBy([
            'oauthId' => $oauthId,
            'oauthProvider' => 'generic',
        ]);

        if (!$user) {
            // 2. Search by username to link account
            $existingUserByUsername = $this->userRepository->findOneBy(['username' => $username]);
            if ($existingUserByUsername) {
                $user = $existingUserByUsername;

                if ($user->isBanned()) {
                    throw new CustomUserMessageAuthenticationException(
                        'Votre compte a été suspendu. Veuillez contacter un administrateur.',
                    );
                }

                if ($email !== null && $user->getEmail() === null) {
                    $user->setEmail($email);
                }

                $user->setOauthId($oauthId);
                $user->setOauthProvider('generic');
                $this->entityManager->flush();
                $this->logger->info(sprintf(
                    'Linked existing user "%s" (ID: %d) with OAuth2 ID "%s".',
                    $username,
                    $user->getId(),
                    $oauthId,
                ));
            } else {
                // 3. Create a brand new user
                $user = new User();
                $user->setUsername($username);
                $user->setDisplayName($username);
                $user->setOauthId($oauthId);
                $user->setOauthProvider('generic');

                if ($email !== null) {
                    $user->setEmail($email);
                    $user->setEmailVerifiedAt(new \DateTimeImmutable());
                }

                // Set a random secure password
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
            }
        } else {
            if ($user->isBanned()) {
                throw new CustomUserMessageAuthenticationException(
                    'Votre compte a été suspendu. Veuillez contacter un administrateur.',
                );
            }

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

            $this->logger->debug(sprintf(
                'User "%s" authenticated via OAuth2 with provider ID "%s".',
                $user->getUsername(),
                $oauthId,
            ));
        }

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn() => $user));
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
