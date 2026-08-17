<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Entity\Reaction;
use App\Repository\MessageRepository;
use App\Repository\ReactionRepository;
use App\Service\KanbanManager;
use App\Service\MessageBroadcaster;
use App\Service\MessageRenderer;
use App\Service\SidebarDataProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class ReactionController extends AbstractController
{
    use ChannelAccessTrait;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly KanbanManager $kanbanManager,
        private readonly MessageRenderer $messageRenderer,
        private readonly SidebarDataProvider $sidebarDataProvider,
    ) {}

    #[Route('/messages/{id}/react/{emoji}', name: 'app_message_react', methods: ['POST'])]
    public function react(
        int $id,
        string $emoji,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        MessageBroadcaster $messageBroadcaster,
    ): Response {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        $this->authorizeMessageAccess($message);

        $channel = $message->getChannel();

        // Allow any emoji/character sequence as long as it is short enough to fit in the DB and prevent abuse
        if (mb_strlen($emoji) < 1 || mb_strlen($emoji) > 16) {
            return new Response($this->translator->trans('Emoji non supporté.'), 400);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $reactionRepo = $entityManager->getRepository(Reaction::class);
        $existingReaction = $reactionRepo->findOneBy([
            'message' => $message,
            'user' => $currentUser,
            'emoji' => $emoji,
        ]);

        if ($existingReaction) {
            $entityManager->remove($existingReaction);
        } else {
            $reaction = new Reaction();
            $reaction->setMessage($message);
            $reaction->setUser($currentUser);
            $reaction->setEmoji($emoji);
            $entityManager->persist($reaction);
        }

        $entityManager->flush();

        // Sync completion state for todo channels
        if ($channel->isTodoList() && $emoji === '✅') {
            $hasCheck = false;
            foreach ($message->getReactions() as $r) {
                if ($r->getEmoji() !== '✅') { continue; }

$hasCheck = true;
                    break;
            }
            $hasCheck
                ? $this->kanbanManager->markAsCompleted($message, $currentUser)
                : $this->kanbanManager->markAsIncomplete($message, $currentUser);
        }

        $renderedHtml = $this->messageRenderer->renderFeedItem($message, ['no_fade' => true]);
        $messageBroadcaster->broadcastMessageUpdate($message);

        return new Response($renderedHtml);
    }

    #[Route('/my-reactions', name: 'app_my_reactions', methods: ['GET'])]
    #[Route('/my-reactions/{emoji}', name: 'app_my_reactions_filtered', methods: ['GET'])]
    public function myReactions(
        Request $request,
        ReactionRepository $reactionRepository,
        ?string $emoji = null,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $beforeId = $request->query->getInt('beforeId');
        $beforeId = $beforeId > 0 ? $beforeId : null;

        if ($beforeId !== null) {
            $messages = $emoji
                ? $reactionRepository->findDistinctMessagesByUserAndEmoji($currentUser, $emoji, 50, $beforeId)
                : $reactionRepository->findDistinctMessagesByUser($currentUser, 50, $beforeId);
            $hasMore = count($messages) === 50;
            $nextBeforeId = $hasMore ? $messages[array_key_last($messages)]->getId() : null;

            return $this->render('dashboard/_more_my_reactions.html.twig', [
                'reactedMessages' => $messages,
                'hasMore' => $hasMore,
                'nextBeforeId' => $nextBeforeId,
                'activeEmoji' => $emoji,
            ]);
        }

        $sidebarParams = $this->sidebarDataProvider->getSidebarData($currentUser);

        $messages = $emoji
            ? $reactionRepository->findDistinctMessagesByUserAndEmoji($currentUser, $emoji, 50)
            : $reactionRepository->findDistinctMessagesByUser($currentUser, 50);
        $userEmojis = $reactionRepository->findUserEmojis($currentUser);
        $hasMore = count($messages) === 50;
        $nextBeforeId = $hasMore ? $messages[array_key_last($messages)]->getId() : null;

        return $this->render('dashboard/my_reactions.html.twig', array_merge([
            'reactedMessages' => $messages,
            'userEmojis' => $userEmojis,
            'activeEmoji' => $emoji,
            'activeChannel' => null,
            'hasMore' => $hasMore,
            'nextBeforeId' => $nextBeforeId,
        ], $sidebarParams));
    }
}
