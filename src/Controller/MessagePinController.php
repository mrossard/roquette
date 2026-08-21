<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\MessageRepository;
use App\Service\ChannelManager;
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
        private readonly ChannelManager $channelManager,
    ) {}

    #[Route('/messages/{id}/pin', name: 'app_message_pin', methods: ['POST'])]
    public function pinMessage(int $id, MessageRepository $messageRepository): Response
    {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        /** @var User $currentUser */
        $channel = $message->getChannel();
        $this->denyAccessUnlessGranted('EDIT', $channel);

        $bannerHtml = $this->renderView('dashboard/_pinned_banner.html.twig', [
            'pinnedMessage' => $message,
            'activeChannel' => $channel,
        ]);

        $this->channelManager->pinMessage($message, $bannerHtml);

        return new Response($bannerHtml);
    }

    #[Route('/messages/{id}/unpin', name: 'app_message_unpin', methods: ['POST'])]
    public function unpinMessage(int $id, MessageRepository $messageRepository): Response
    {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        /** @var User $currentUser */
        $channel = $message->getChannel();
        $this->denyAccessUnlessGranted('EDIT', $channel);

        $this->channelManager->unpinMessage($message);

        return new Response('');
    }
}
