<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Account\ChangePasswordDto;
use App\Dto\Account\UpdateNotificationPreferencesDto;
use App\Dto\Account\UpdateProfileDto;
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
    public function updateProfile(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $dto = UpdateProfileDto::fromRequest($request);

        $currentUser->setDisplayName($dto->displayName);

        if ($dto->hue !== null) {
            $currentUser->setCustomHue($dto->hue);
        }

        if ($dto->statusOverride !== null || $request->request->get('statusOverride') === 'auto') {
            $currentUser->setStatusOverride($dto->statusOverride);
        }

        if ($dto->locale !== null) {
            $currentUser->setLocale($dto->locale);
            $request->getSession()->set('_locale', $dto->locale);
            $request->setLocale($dto->locale);
        }

        $entityManager->flush();

        $this->mercurePublisher->publishUserStatus($currentUser);

        $this->addFlash('success', $this->translator->trans('Votre profil a été mis à jour avec succès !'));

        return $this->redirectToRoute('app_account');
    }

    #[Route('/account/notifications', name: 'app_account_notifications', methods: ['POST'])]
    public function updateNotifications(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $dto = UpdateNotificationPreferencesDto::fromRequest($request);
        $currentUser->setMentionNotificationsEnabled($dto->mentionNotificationsEnabled);

        $entityManager->flush();

        $this->addFlash('success', $this->translator->trans('Vos préférences de notification ont été mises à jour !'));

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

        $dto = ChangePasswordDto::fromRequest($request);

        if (!$dto->isFilled()) {
            $this->addFlash('error', $this->translator->trans('Tous les champs de mot de passe sont obligatoires.'));
            return $this->redirectToRoute('app_account');
        }

        if (!$passwordHasher->isPasswordValid($currentUser, $dto->currentPassword)) {
            $this->addFlash('error', $this->translator->trans('Le mot de passe actuel est incorrect.'));
            return $this->redirectToRoute('app_account');
        }

        if (!$dto->arePasswordsMatching()) {
            $this->addFlash(
                'error',
                $this->translator->trans('Le nouveau mot de passe et sa confirmation ne correspondent pas.'),
            );
            return $this->redirectToRoute('app_account');
        }

        if (!$dto->isLengthValid(6)) {
            $this->addFlash(
                'error',
                $this->translator->trans('Le nouveau mot de passe doit faire au moins 6 caractères.'),
            );
            return $this->redirectToRoute('app_account');
        }

        $hashed = $passwordHasher->hashPassword($currentUser, $dto->newPassword);
        $currentUser->setPassword($hashed);
        $entityManager->flush();
        $this->addFlash('success', $this->translator->trans('Votre mot de passe a été modifié avec succès !'));

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
