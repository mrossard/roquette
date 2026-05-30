<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\MessageRendererTrait;
use App\Entity\Reaction;
use App\Repository\MessageRepository;
use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ReactionController extends AbstractController
{
    use MessageRendererTrait;

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
            return new Response('Message non trouvé.', 404);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $channel = $message->getChannel();
        if (($channel->isPrivate() || $channel->isDm()) && !$channel->getMembers()->contains($currentUser)) {
            return new Response('Non autorisé.', 403);
        }

        $allowedEmojis = ['👍', '❤️', '😂', '😮', '😢', '🎉'];
        if (!in_array($emoji, $allowedEmojis, true)) {
            return new Response('Emoji non supporté.', 400);
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

        $renderedHtml = $this->renderFeedItem($message);

        $mercurePublisher->publishToChannel($channel, [
            'html' => $renderedHtml,
            'user' => $currentUser->getUsername(),
            'channelSlug' => $channel->getSlug(),
        ]);

        return new Response($renderedHtml);
    }
}
