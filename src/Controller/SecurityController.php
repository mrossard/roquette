<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'bool:AUTH_FORM_ENABLED')]
        private bool $authFormEnabled,
        #[Autowire(env: 'bool:AUTH_OAUTH_ENABLED')]
        private bool $authOauthEnabled,
    ) {}

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'auth_form_enabled' => $this->authFormEnabled,
            'auth_oauth_enabled' => $this->authOauthEnabled,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // controller can be blank: it will never be executed!
        throw new \LogicException(
            'This method can be blank - it will be intercepted by the logout key on your firewall.',
        );
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        RateLimiterFactoryInterface $loginApiLimiter,
        \Psr\Log\LoggerInterface $logger,
    ): Response {
        if (!$this->authFormEnabled) {
            throw $this->createAccessDeniedException('L\'inscription est désactivée.');
        }

        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $user = new User();
        $form = $this->createForm(\App\Form\RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limiter = $loginApiLimiter->create($request->getClientIp());
            if (false === $limiter->consume(1)->isAccepted()) {
                $logger->warning(sprintf('Registration rate limit exceeded for IP %s', $request->getClientIp()));
                $this->addFlash('error', 'Trop de tentatives d\'inscription. Veuillez réessayer plus tard.');
                return $this->render(
                    'security/register.html.twig',
                    [
                        'registrationForm' => $form->createView(),
                    ],
                    new Response('', Response::HTTP_TOO_MANY_REQUESTS),
                );
            }

            $user->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $user->setRoles(['ROLE_USER']);
            $user->setAdmin(false);

            $entityManager->persist($user);
            $entityManager->flush();

            $logger->info(sprintf(
                'New user account registered: "%s" (Roles: %s)',
                $user->getUsername(),
                implode(', ', $user->getRoles()),
            ));

            $this->addFlash('success', 'Votre compte a été créé avec succès ! Connectez-vous maintenant.');
            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            $limiter = $loginApiLimiter->create($request->getClientIp());
            if (false === $limiter->consume(1)->isAccepted()) {
                $logger->warning(sprintf(
                    'Registration rate limit exceeded for IP %s during POST verification',
                    $request->getClientIp(),
                ));
                $this->addFlash('error', 'Trop de tentatives d\'inscription. Veuillez réessayer plus tard.');
                return $this->render(
                    'security/register.html.twig',
                    [
                        'registrationForm' => $form->createView(),
                    ],
                    new Response('', Response::HTTP_TOO_MANY_REQUESTS),
                );
            }
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }
}
