<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\MessageRepository;
use App\Service\MessageBroadcaster;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class MessagePinController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/messages/{id}/pin', name: 'app_message_pin', methods: ['POST'])]
    public function pinMessage(
        int $id,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        MessageBroadcaster $messageBroadcaster,
    ): Response {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        /** @var User $currentUser */
        $channel = $message->getChannel();
        $this->denyAccessUnlessGranted('EDIT', $channel);

        $previousPinnedMessage = $channel->getPinnedMessage();
        $channel->setPinnedMessage($message);
        $entityManager->flush();

        $bannerHtml = $this->renderView('dashboard/_pinned_banner.html.twig', [
            'pinnedMessage' => $message,
            'activeChannel' => $channel,
        ]);

        $messageBroadcaster->broadcastPin($channel, $message, $previousPinnedMessage, $bannerHtml);

        return new Response($bannerHtml);
    }

    #[Route('/messages/{id}/unpin', name: 'app_message_unpin', methods: ['POST'])]
    public function unpinMessage(
        int $id,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        MessageBroadcaster $messageBroadcaster,
    ): Response {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        /** @var User $currentUser */
        $channel = $message->getChannel();
        $this->denyAccessUnlessGranted('EDIT', $channel);

        if ($channel->getPinnedMessage() === $message) {
            $channel->setPinnedMessage(null);
            $entityManager->flush();

            $messageBroadcaster->broadcastUnpin($channel, $message);
        }

        return new Response('');
    }
}
