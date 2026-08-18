<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\EmailVerificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EmailVerificationController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly EmailVerificationService $emailVerificationService,
    ) {}

    #[Route('/verify-email/resend', name: 'app_resend_verification', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function resend(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->isEmailVerified()) {
            $this->addFlash('info', $this->translator->trans('Votre adresse email est déjà vérifiée.'));
            return $this->redirectToRoute('app_account');
        }

        if ($user->getEmail() === null) {
            $this->addFlash('error', $this->translator->trans('Aucune adresse email associée à votre compte.'));
            return $this->redirectToRoute('app_account');
        }

        if (!$this->emailVerificationService->sendVerificationEmail($user)) {
            $this->addFlash(
                'error',
                $this->translator->trans(
                    'Impossible d\'envoyer l\'email de vérification. Veuillez réessayer plus tard.',
                ),
            );

            return $this->redirectToRoute('app_account');
        }

        $this->addFlash('success', $this->translator->trans('Email de vérification renvoyé !'));

        return $this->redirectToRoute('app_account');
    }

    #[Route(
        '/verify-email/{token}',
        name: 'app_verify_email',
        requirements: ['token' => '[a-f0-9]{64}'],
        methods: ['GET'],
    )]
    public function verify(#[\SensitiveParameter] string $token): Response
    {
        $user = $this->emailVerificationService->verifyEmailToken($token);

        if ($user === null) {
            $this->addFlash('error', $this->translator->trans('Lien de vérification invalide ou expiré.'));
            return $this->redirectToRoute('app_login');
        }

        $this->addFlash('success', $this->translator->trans('Votre adresse email a été vérifiée avec succès !'));
        return $this->redirectToRoute('app_dashboard');
    }
}
