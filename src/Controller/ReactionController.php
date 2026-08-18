<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Repository\MessageRepository;
use App\Repository\ReactionRepository;
use App\Service\MessageRenderer;
use App\Service\ReactionManager;
use App\Service\SidebarDataProvider;
use InvalidArgumentException;
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
        private readonly ReactionManager $reactionManager,
        private readonly MessageRenderer $messageRenderer,
        private readonly SidebarDataProvider $sidebarDataProvider,
    ) {}

    #[Route('/messages/{id}/react/{emoji}', name: 'app_message_react', methods: ['POST'])]
    public function react(
        int $id,
        string $emoji,
        MessageRepository $messageRepository,
    ): Response {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        $this->authorizeMessageAccess($message);

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        try {
            $this->reactionManager->toggleReaction($message, $currentUser, $emoji);
        } catch (InvalidArgumentException) {
            return new Response($this->translator->trans('Emoji non supporté.'), 400);
        }

        $renderedHtml = $this->messageRenderer->renderFeedItem($message, ['no_fade' => true]);

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
