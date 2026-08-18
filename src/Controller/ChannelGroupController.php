<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ChannelEditModalDataProvider;
use App\Service\ChannelManager;
use App\Service\Group\GroupSubscriptionManager;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ChannelGroupController extends AbstractController
{
    public function __construct(
        private readonly GroupSubscriptionManager $groupSubscriptionManager,
        private readonly ChannelEditModalDataProvider $modalDataProvider,
    ) {}

    #[Route('/channels/{slug}/subscribe-group', name: 'app_channel_subscribe_group', methods: ['POST'])]
    public function subscribeGroup(string $slug, Request $request, ChannelManager $channelManager): Response
    {
        $channel = $channelManager->findChannelBySlug($slug);
        $this->denyAccessUnlessGranted('MANAGE', $channel);

        $newGroupIdentifier = $request->request->getString('newGroupIdentifier');
        if ($newGroupIdentifier !== '') {
            $isOfficial = $request->request->getBoolean('newGroupIsOfficial', false);

            try {
                $isOfficial
                    ? $this->groupSubscriptionManager->subscribeOfficial($channel, $newGroupIdentifier)
                    : $this->groupSubscriptionManager->subscribe($channel, $newGroupIdentifier);
            } catch (InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render(
            'modals/_edit_channel_modal.html.twig',
            $this->modalDataProvider->getEditModalData($channel),
        );
    }

    #[Route(
        '/channels/{slug}/unsubscribe-group/{subscriptionId}',
        name: 'app_channel_unsubscribe_group',
        methods: ['POST'],
    )]
    public function unsubscribeGroup(string $slug, int $subscriptionId, ChannelManager $channelManager): Response
    {
        $channel = $channelManager->findChannelBySlug($slug);
        $this->denyAccessUnlessGranted('MANAGE', $channel);

        $this->groupSubscriptionManager->unsubscribe($channel, $subscriptionId);

        return $this->render(
            'modals/_edit_channel_modal.html.twig',
            $this->modalDataProvider->getEditModalData($channel),
        );
    }
}
