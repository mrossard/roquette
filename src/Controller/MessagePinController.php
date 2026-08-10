<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Service\MercurePublisher;
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
        MercurePublisher $mercurePublisher,
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
        $bannerOob = '<div id="pinned-banner-container" hx-swap-oob="true">' . $bannerHtml . '</div>';
        $messageHtml = $this->renderMessageItem($message, true);

        $previousMessageHtml = '';
        if ($previousPinnedMessage) {
            $previousMessageHtml = $this->renderMessageItem($previousPinnedMessage, true);
        }

        $mercurePublisher->publishToChannel(
            $channel,
            $bannerOob . $messageHtml . $previousMessageHtml,
            'message_' . $channel->getSlug(),
        );

        return new Response($bannerHtml);
    }

    #[Route('/messages/{id}/unpin', name: 'app_message_unpin', methods: ['POST'])]
    public function unpinMessage(
        int $id,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        MercurePublisher $mercurePublisher,
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

            $bannerOob = '<div id="pinned-banner-container" hx-swap-oob="true"></div>';
            $messageHtml = $this->renderMessageItem($message, true);

            $mercurePublisher->publishToChannel($channel, $bannerOob . $messageHtml, 'message_' . $channel->getSlug());
        }

        return new Response('');
    }

    private function renderMessageItem(Message $message, bool $oob = false): string
    {
        return $this->renderView('dashboard/_feed_item.html.twig', [
            'author' => $message->getAuthor(),
            'message' => $message->getContent(),
            'timestamp' => $message->getCreatedAt(),
            'message_id' => $message->getId(),
            'updated_at' => $message->getUpdatedAt(),
            'fileName' => $message->getFileName(),
            'fileSize' => $message->getFileSize(),
            'filePath' => $message->getFilePath(),
            'mimeType' => $message->getMimeType(),
            'messageObject' => $message,
            'oob' => $oob,
        ]);
    }
}
