<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EmailVerificationController extends AbstractController
{
    public function __construct(
        private TranslatorInterface $translator,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {}

    #[Route('/verify-email/{token}', name: 'app_verify_email', methods: ['GET'])]
    public function verify(#[\SensitiveParameter] string $token, EntityManagerInterface $entityManager): Response
    {
        $user = $entityManager
            ->getRepository(User::class)
            ->findOneBy([
                'emailVerificationToken' => $token,
            ]);

        if ($user === null) {
            $this->addFlash('error', $this->translator->trans('Lien de vérification invalide ou expiré.'));
            return $this->redirectToRoute('app_login');
        }

        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setEmailVerificationToken(null);
        $entityManager->flush();

        $this->logger->info(sprintf('Email verified for user "%s" (ID: %d).', $user->getUsername(), $user->getId()));

        $this->addFlash('success', $this->translator->trans('Votre adresse email a été vérifiée avec succès !'));
        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/verify-email/resend', name: 'app_resend_verification', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function resend(EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->isEmailVerified()) {
            $this->addFlash('info', $this->translator->trans('Votre adresse email est déjà vérifiée.'));
            return $this->redirectToRoute('app_account');
        }

        $email = $user->getEmail();
        if ($email === null) {
            $this->addFlash('error', $this->translator->trans('Aucune adresse email associée à votre compte.'));
            return $this->redirectToRoute('app_account');
        }

        $token = bin2hex(random_bytes(32));
        $user->setEmailVerificationToken($token);
        $entityManager->flush();

        $verificationUrl = $this->generateUrl(
            'app_verify_email',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        try {
            $emailMessage = new Email()
                ->from('noreply@roquette.local')
                ->to($email)
                ->subject($this->translator->trans('Vérification de votre adresse email Roquette'))
                ->html($this->renderView('emails/verify_email.html.twig', [
                    'user' => $user,
                    'verificationUrl' => $verificationUrl,
                ]))
                ->text($this->renderView('emails/verify_email.txt.twig', [
                    'user' => $user,
                    'verificationUrl' => $verificationUrl,
                ]));

            $this->mailer->send($emailMessage);
            $this->logger->info(sprintf(
                'Verification email resent to "%s" for user "%s".',
                $email,
                $user->getUsername(),
            ));

            $this->addFlash('success', $this->translator->trans('Email de vérification renvoyé !'));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Failed to resend verification email to "%s" for user "%s": %s',
                $email,
                $user->getUsername(),
                $e->getMessage(),
            ));
            $this->addFlash(
                'error',
                $this->translator->trans(
                    'Impossible d\'envoyer l\'email de vérification. Veuillez réessayer plus tard.',
                ),
            );
        }

        return $this->redirectToRoute('app_account');
    }
}
