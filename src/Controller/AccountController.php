<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class AccountController extends AbstractController
{
    public function __construct(
        private string $mercureTopicPrefix
    ) {}

    #[Route('/account', name: 'app_account', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        ChannelRepository $channelRepository,
        MessageBusInterface $bus
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        // Fetch user channels so we can still render base sidebar/layout components
        $channels = $channelRepository->findAllForUser($currentUser);

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');

            if ($action === 'profile') {
                $displayName = trim($request->request->get('displayName', ''));
                $hue = $request->request->get('hue');
                $statusOverride = $request->request->get('statusOverride');

                $currentUser->setDisplayName(empty($displayName) ? null : $displayName);

                if ($hue !== null) {
                    $hueVal = (int) $hue;
                    if ($hueVal >= 0 && $hueVal <= 360) {
                        $currentUser->setCustomHue($hueVal);
                    }
                }

                if (in_array($statusOverride, ['auto', 'online', 'away', 'busy', 'offline'], true)) {
                    $currentUser->setStatusOverride($statusOverride === 'auto' ? null : $statusOverride);
                }

                $mentionNotificationsEnabled = (bool) $request->request->get('mentionNotificationsEnabled');
                $currentUser->setMentionNotificationsEnabled($mentionNotificationsEnabled);

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
                        'lastActive' => $currentUser->getLastActiveAt() ? $currentUser->getLastActiveAt()->getTimestamp() : null
                    ])
                );
                $bus->dispatch($update);

                $this->addFlash('success', 'Votre profil a été mis à jour avec succès !');
            } elseif ($action === 'password') {
                $currentPassword = $request->request->get('currentPassword', '');
                $newPassword = $request->request->get('newPassword', '');
                $confirmPassword = $request->request->get('confirmPassword', '');

                if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                    $this->addFlash('error', 'Tous les champs de mot de passe sont obligatoires.');
                } elseif (!$passwordHasher->isPasswordValid($currentUser, $currentPassword)) {
                    $this->addFlash('error', 'Le mot de passe actuel est incorrect.');
                } elseif ($newPassword !== $confirmPassword) {
                    $this->addFlash('error', 'Le nouveau mot de passe et sa confirmation ne correspondent pas.');
                } elseif (strlen($newPassword) < 6) {
                    $this->addFlash('error', 'Le nouveau mot de passe doit faire au moins 6 caractères.');
                } else {
                    $hashed = $passwordHasher->hashPassword($currentUser, $newPassword);
                    $currentUser->setPassword($hashed);
                    $entityManager->flush();
                    $this->addFlash('success', 'Votre mot de passe a été modifié avec succès !');
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
