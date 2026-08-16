<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly MercurePublisher $mercurePublisher,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/account', name: 'app_account', methods: ['GET'])]
    public function index(ChannelRepository $channelRepository): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Fetch user channels so we can still render base sidebar/layout components
        $channels = $channelRepository->findAllForUser($currentUser);

        return $this->render('account/index.html.twig', [
            'channels' => $channels,
            'user' => $currentUser,
        ]);
    }

    #[Route('/account/profile', name: 'app_account_profile', methods: ['POST'])]
    public function updateProfile(
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $displayName = trim((string) $request->request->get('displayName', ''));
        $hue = $request->request->get('hue');
        $statusOverride = $request->request->get('statusOverride');

        $currentUser->setDisplayName($displayName === '' ? null : $displayName);

        if ($hue !== null) {
            $hueVal = (int) $hue;
            if ($hueVal >= 0 && $hueVal <= 360) {
                $currentUser->setCustomHue($hueVal);
            }
        }

        if (\in_array($statusOverride, ['auto', 'online', 'away', 'busy', 'offline'], true)) {
            $currentUser->setStatusOverride($statusOverride === 'auto' ? null : $statusOverride);
        }

        $locale = $request->request->get('locale');
        if (\in_array($locale, ['fr', 'en'], true)) {
            $currentUser->setLocale($locale);
            $request->getSession()->set('_locale', $locale);
            $request->setLocale($locale);
        }

        $entityManager->flush();

        $this->mercurePublisher->publishUserStatus($currentUser);

        $this->addFlash('success', $this->translator->trans('Votre profil a été mis à jour avec succès !'));

        return $this->redirectToRoute('app_account');
    }

    #[Route('/account/notifications', name: 'app_account_notifications', methods: ['POST'])]
    public function updateNotifications(
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $mentionNotificationsEnabled = (bool) $request->request->get('mentionNotificationsEnabled');
        $currentUser->setMentionNotificationsEnabled($mentionNotificationsEnabled);

        $entityManager->flush();

        $this->addFlash(
            'success',
            $this->translator->trans('Vos préférences de notification ont été mises à jour !'),
        );

        return $this->redirectToRoute('app_account');
    }

    #[Route('/account/password', name: 'app_account_password', methods: ['POST'])]
    public function updatePassword(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $currentPassword = (string) $request->request->get('currentPassword', '');
        $newPassword = (string) $request->request->get('newPassword', '');
        $confirmPassword = (string) $request->request->get('confirmPassword', '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $this->addFlash(
                'error',
                $this->translator->trans('Tous les champs de mot de passe sont obligatoires.'),
            );
            return $this->redirectToRoute('app_account');
        }

        if (!$passwordHasher->isPasswordValid($currentUser, $currentPassword)) {
            $this->addFlash('error', $this->translator->trans('Le mot de passe actuel est incorrect.'));
            return $this->redirectToRoute('app_account');
        }

        if (!hash_equals($newPassword, $confirmPassword)) {
            $this->addFlash(
                'error',
                $this->translator->trans('Le nouveau mot de passe et sa confirmation ne correspondent pas.'),
            );
            return $this->redirectToRoute('app_account');
        }

        if (mb_strlen($newPassword) < 6) {
            $this->addFlash(
                'error',
                $this->translator->trans('Le nouveau mot de passe doit faire au moins 6 caractères.'),
            );
            return $this->redirectToRoute('app_account');
        }

        $hashed = $passwordHasher->hashPassword($currentUser, $newPassword);
        $currentUser->setPassword($hashed);
        $entityManager->flush();
        $this->addFlash(
            'success',
            $this->translator->trans('Votre mot de passe a été modifié avec succès !'),
        );

        return $this->redirectToRoute('app_account');
    }

    /**
     * Legacy fallback for POST /account?action=...
     */
    #[Route('/account', name: 'app_account_legacy_post', methods: ['POST'])]
    public function legacyPost(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
    ): Response {
        $action = $request->request->get('action');

        return match ($action) {
            'profile' => $this->updateProfile($request, $entityManager),
            'notifications' => $this->updateNotifications($request, $entityManager),
            'password' => $this->updatePassword($request, $passwordHasher, $entityManager),
            default => $this->redirectToRoute('app_account'),
        };
    }
}
