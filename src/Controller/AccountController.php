<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly string $mercureTopicPrefix,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/account', name: 'app_account', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        ChannelRepository $channelRepository,
        MessageBusInterface $bus,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Fetch user channels so we can still render base sidebar/layout components
        $channels = $channelRepository->findAllForUser($currentUser);

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');

            if ($action === 'profile') {
                $displayName = trim($request->request->get('displayName', ''));
                $hue = $request->request->get('hue');
                $statusOverride = $request->request->get('statusOverride');

                $currentUser->setDisplayName($displayName === '' ? null : $displayName);

                if ($hue !== null) {
                    $hueVal = (int) $hue;
                    if ($hueVal >= 0 && $hueVal <= 360) {
                        $currentUser->setCustomHue($hueVal);
                    }
                }

                if (in_array($statusOverride, ['auto', 'online', 'away', 'busy', 'offline'], true)) {
                    $currentUser->setStatusOverride($statusOverride === 'auto' ? null : $statusOverride);
                }

                $locale = $request->request->get('locale');
                if (in_array($locale, ['fr', 'en'], true)) {
                    $currentUser->setLocale($locale);
                    $request->getSession()->set('_locale', $locale);
                    $request->setLocale($locale);
                }

                $entityManager->flush();

                // Publish status change via Mercure
                $update = new Update(
                    $this->mercureTopicPrefix . '/users/status',
                    json_encode([
                        'type' => 'user_status_changed',
                        'username' => $currentUser->getUsername(),
                        'status' => $currentUser->getStatus(),
                        'statusLabel' => $currentUser->getStatusLabel(),
                        'statusOverride' => $currentUser->getStatusOverride() ?? 'auto',
                        'lastActive' => $currentUser->getLastActiveAt()?->getTimestamp(),
                    ]),
                    true,
                    null,
                    'user_status_changed',
                );
                $bus->dispatch($update);

                $this->addFlash('success', $this->translator->trans('Votre profil a été mis à jour avec succès !'));
            } elseif ($action === 'notifications') {
                $mentionNotificationsEnabled = (bool) $request->request->get('mentionNotificationsEnabled');
                $currentUser->setMentionNotificationsEnabled($mentionNotificationsEnabled);

                $entityManager->flush();

                $this->addFlash(
                    'success',
                    $this->translator->trans('Vos préférences de notification ont été mises à jour !'),
                );
            } elseif (hash_equals('password', $action ?? '')) {
                $currentPassword = (string) $request->request->get('currentPassword', '');
                $newPassword = (string) $request->request->get('newPassword', '');
                $confirmPassword = (string) $request->request->get('confirmPassword', '');

                if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                    $this->addFlash(
                        'error',
                        $this->translator->trans('Tous les champs de mot de passe sont obligatoires.'),
                    );
                } elseif (!$passwordHasher->isPasswordValid($currentUser, $currentPassword)) {
                    $this->addFlash('error', $this->translator->trans('Le mot de passe actuel est incorrect.'));
                } elseif (!hash_equals($newPassword, $confirmPassword)) {
                    $this->addFlash(
                        'error',
                        $this->translator->trans('Le nouveau mot de passe et sa confirmation ne correspondent pas.'),
                    );
                } elseif (strlen($newPassword) < 6) {
                    $this->addFlash(
                        'error',
                        $this->translator->trans('Le nouveau mot de passe doit faire au moins 6 caractères.'),
                    );
                } else {
                    $hashed = $passwordHasher->hashPassword($currentUser, $newPassword);
                    $currentUser->setPassword($hashed);
                    $entityManager->flush();
                    $this->addFlash(
                        'success',
                        $this->translator->trans('Votre mot de passe a été modifié avec succès !'),
                    );
                }
            }

            return $this->redirectToRoute('app_account');
        }

        return $this->render('account/index.html.twig', [
            'channels' => $channels,
            'user' => $currentUser,
        ]);
    }
}
