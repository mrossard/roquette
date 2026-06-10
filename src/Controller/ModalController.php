<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ChannelRepository;
use App\Repository\WebhookRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ModalController extends AbstractController
{
    #[Route('/channels/{slug}/edit-modal', name: 'app_channel_edit_modal', methods: ['GET'])]
    public function editModal(
        string $slug,
        ChannelRepository $channelRepository,
        WebhookRepository $webhookRepository,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $channel = $channelRepository->findOneBy(['slug' => $slug]);

        if (!$channel) {
            return new Response('Canal non trouvé', 404);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$channel->isAdministrator($currentUser)) {
            return new Response('Accès refusé', 403);
        }

        $webhooks = $webhookRepository->findBy(['channel' => $channel], ['createdAt' => 'ASC']);

        return $this->render('_edit_channel_modal.html.twig', [
            'activeChannel' => $channel,
            'webhooks' => $webhooks,
        ]);
    }

    #[Route('/channels/{slug}/invite-modal', name: 'app_channel_invite_modal', methods: ['GET'])]
    public function inviteModal(string $slug, ChannelRepository $channelRepository): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $channel = $channelRepository->findOneBy(['slug' => $slug]);

        if (!$channel) {
            return new Response('Canal non trouvé', 404);
        }

        if ($channel->isDm() || !$channel->isPrivate() || $channel->getCreator() !== $currentUser) {
            return new Response('Accès refusé', 403);
        }

        return $this->render('_invite_member_modal.html.twig', [
            'activeChannel' => $channel,
            'usersToInvite' => [], // Starting empty, search is done via AJAX
        ]);
    }

    #[Route('/channels/{slug}/members-modal', name: 'app_channel_members_modal', methods: ['GET'])]
    public function membersModal(string $slug, ChannelRepository $channelRepository): Response
    {
        $channel = $channelRepository->findOneBy(['slug' => $slug]);

        if (!$channel) {
            return new Response('Canal non trouvé', 404);
        }

        return $this->render('_channel_members_modal.html.twig', [
            'activeChannel' => $channel,
        ]);
    }

    #[Route('/channels/create-modal', name: 'app_channel_create_modal', methods: ['GET'])]
    public function createModal(): Response
    {
        return $this->render('_create_channel_modal.html.twig');
    }
}
