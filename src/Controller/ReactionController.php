<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\MessageRendererTrait;
use App\Entity\Reaction;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Repository\ReactionRepository;
use App\Service\MercurePublisher;
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
    use MessageRendererTrait;

    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    #[Route('/messages/{id}/react/{emoji}', name: 'app_message_react', methods: ['POST'])]
    public function react(
        int $id,
        string $emoji,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        MercurePublisher $mercurePublisher,
    ): Response {
        $message = $messageRepository->find($id);
        if (!$message) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channel = $message->getChannel();
        if (($channel->isPrivate() || $channel->isDm()) && !$channel->getMembers()->contains($currentUser)) {
            return new Response($this->translator->trans('Non autorisé.'), 403);
        }

        // Allow any emoji/character sequence as long as it is short enough to fit in the DB and prevent abuse
        if (mb_strlen($emoji) < 1 || mb_strlen($emoji) > 16) {
            return new Response($this->translator->trans('Emoji non supporté.'), 400);
        }

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

        $renderedHtml = $this->renderFeedItem($message, ['no_fade' => true]);

        $renderedHtmlOob = $this->renderView('dashboard/_feed_item.html.twig', array_merge(
            $this->feedItemParams($message),
            ['oob' => true],
        ));

        $mercurePublisher->publishToChannel($channel, $renderedHtmlOob, 'message_' . $channel->getSlug());

        return new Response($renderedHtml);
    }

    #[Route('/my-reactions', name: 'app_my_reactions', methods: ['GET'])]
    #[Route('/my-reactions/{emoji}', name: 'app_my_reactions_filtered', methods: ['GET'])]
    public function myReactions(
        Request $request,
        ReactionRepository $reactionRepository,
        ChannelRepository $channelRepository,
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

        $channels = $channelRepository->findAllForUser($currentUser);
        $messages = $emoji
            ? $reactionRepository->findDistinctMessagesByUserAndEmoji($currentUser, $emoji, 50)
            : $reactionRepository->findDistinctMessagesByUser($currentUser, 50);
        $userEmojis = $reactionRepository->findUserEmojis($currentUser);
        $hasMore = count($messages) === 50;
        $nextBeforeId = $hasMore ? $messages[array_key_last($messages)]->getId() : null;

        return $this->render('dashboard/my_reactions.html.twig', [
            'channels' => $channels,
            'reactedMessages' => $messages,
            'userEmojis' => $userEmojis,
            'activeEmoji' => $emoji,
            'activeChannel' => null,
            'hasMore' => $hasMore,
            'nextBeforeId' => $nextBeforeId,
        ]);
    }
}
