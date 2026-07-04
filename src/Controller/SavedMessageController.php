<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\SidebarParametersTrait;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Repository\WorkspaceRepository;
use App\Repository\InvitationRepository;
use App\Service\ChannelManager;
use App\Service\MessageManager;
use App\Service\MessageRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class SavedMessageController extends AbstractController
{
    use SidebarParametersTrait;
    #[Route('/messages/{id}/save', name: 'app_message_save_toggle', methods: ['POST'])]
    public function toggleSaveMessage(
        int $id,
        MessageManager $messageManager,
        MessageRenderer $messageRenderer,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        try {
            $message = $messageManager->toggleSaveMessage($id, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        return $this->render('dashboard/_feed_item.html.twig', $messageRenderer->feedItemParams($message));
    }

    #[Route('/saved-messages', name: 'app_saved_messages', methods: ['GET'])]
    #[Route('/saved-messages/more', name: 'app_saved_messages_more', methods: ['GET'])]
    public function savedMessages(
        Request $request,
        ChannelRepository $channelRepository,
        MessageRepository $messageRepository,
        WorkspaceRepository $workspaceRepository,
        InvitationRepository $invitationRepository,
        ChannelManager $channelManager,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $beforeId = $request->query->getInt('beforeId');
        $beforeId = $beforeId > 0 ? $beforeId : null;

        $savedMessages = $messageRepository->findSavedByUser($currentUser, 50, $beforeId);
        $hasMore = count($savedMessages) === 50;
        $nextBeforeId = $hasMore ? $savedMessages[array_key_last($savedMessages)]->getId() : null;

        if ($beforeId !== null) {
            return $this->render('dashboard/_more_saved_messages.html.twig', [
                'savedMessages' => $savedMessages,
                'hasMore' => $hasMore,
                'nextBeforeId' => $nextBeforeId,
            ]);
        }

        $sidebarParams = $this->getSidebarParameters(
            $currentUser,
            $channelRepository,
            $workspaceRepository,
            $invitationRepository,
            $messageRepository,
            $channelManager,
            $entityManager
        );

        return $this->render('dashboard/saved_messages.html.twig', array_merge([
            'savedMessages' => $savedMessages,
            'hasMore' => $hasMore,
            'nextBeforeId' => $nextBeforeId,
            'activeChannel' => null,
        ], $sidebarParams));
    }
}
