<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class EmailVerificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Generates a unique verification token, saves it, and sends the verification email to the user.
     *
     * @return bool True on success, false if the user has no email or if sending failed.
     */
    public function sendVerificationEmail(User $user): bool
    {
        $email = $user->getEmail();
        if ($email === null || $email === '') {
            return false;
        }

        $token = bin2hex(random_bytes(32));
        $user->setEmailVerificationToken($token);
        $this->entityManager->flush();

        $verificationUrl = $this->urlGenerator->generate(
            'app_verify_email',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        try {
            $emailMessage = new Email()
                ->from('noreply@roquette.local')
                ->to($email)
                ->subject($this->translator->trans('Vérification de votre adresse email Roquette'))
                ->html($this->twig->render('emails/verify_email.html.twig', [
                    'user' => $user,
                    'verificationUrl' => $verificationUrl,
                ]))
                ->text($this->twig->render('emails/verify_email.txt.twig', [
                    'user' => $user,
                    'verificationUrl' => $verificationUrl,
                ]));

            $this->mailer->send($emailMessage);
            $this->logger->info(sprintf(
                'Verification email sent to "%s" for user "%s".',
                $email,
                $user->getUsername(),
            ));

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Failed to send verification email to "%s" for user "%s": %s',
                $email,
                $user->getUsername(),
                $e->getMessage(),
            ));

            return false;
        }
    }

    /**
     * Validates the provided token, marks the email as verified, clears the token, and persists the change.
     *
     * @return User|null The verified user or null if token is invalid or not found.
     */
    public function verifyEmailToken(#[\SensitiveParameter] string $token): ?User
    {
        $user = $this->userRepository->findOneBy(['emailVerificationToken' => $token]);
        if ($user === null) {
            return null;
        }

        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setEmailVerificationToken(null);
        $this->entityManager->flush();

        $this->logger->info(sprintf('Email verified for user "%s" (ID: %d).', $user->getUsername(), $user->getId()));

        return $user;
    }
}
