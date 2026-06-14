<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GroupSubscription;
use App\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class ChannelGroupController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/channels/{slug}/subscribe-group', name: 'app_channel_subscribe_group', methods: ['POST'])]
    public function subscribeGroup(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
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

        $newGroupIdentifier = $request->request->get('newGroupIdentifier');
        if ($newGroupIdentifier !== null && $newGroupIdentifier !== '') {
            $isOfficial = $request->request->getBoolean('newGroupIsOfficial', false);

            if ($isOfficial) {
                $existingGroupSub = $entityManager->getRepository(GroupSubscription::class)->findOneBy([
                    'groupIdentifier' => $newGroupIdentifier,
                    'isGroupChannel' => true,
                ]);
                if ($existingGroupSub) {
                    $this->addFlash('error', $this->translator->trans('Ce groupe possède déjà un canal officiel.'));
                    return $this->forward(ModalController::class . '::editModal', ['slug' => $slug]);
                }
            }

            $existingSub = $entityManager->getRepository(GroupSubscription::class)->findOneBy([
                'channel' => $channel,
                'groupIdentifier' => $newGroupIdentifier,
            ]);

            if (!$existingSub) {
                $sub = new GroupSubscription();
                $sub->setGroupIdentifier($newGroupIdentifier);
                $sub->setIsGroupChannel($isOfficial);
                $channel->addGroupSubscription($sub);
                $entityManager->persist($sub);
                $entityManager->flush();
            }
        }

        return $this->forward(ModalController::class . '::editModal', ['slug' => $slug]);
    }

    #[Route('/channels/{slug}/unsubscribe-group/{subscriptionId}', name: 'app_channel_unsubscribe_group', methods: ['POST'])]
    public function unsubscribeGroup(
        string $slug,
        int $subscriptionId,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
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

        $subscription = $entityManager->getRepository(GroupSubscription::class)->find($subscriptionId);
        if ($subscription && $subscription->getChannel() === $channel) {
            $channel->removeGroupSubscription($subscription);
            $entityManager->remove($subscription);
            $entityManager->flush();
        }

        return $this->forward(ModalController::class . '::editModal', ['slug' => $slug]);
    }
}
