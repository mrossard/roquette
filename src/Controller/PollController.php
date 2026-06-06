<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\MessageRendererTrait;
use App\Entity\PollOption;
use App\Entity\PollVote;
use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class PollController extends AbstractController
{
    use MessageRendererTrait;

    #[Route('/poll/option-field', name: 'app_poll_option_field', methods: ['POST'])]
    public function getOptionField(Request $request): Response
    {
        $count = (int)$request->request->get('count', 0);
        if ($count >= 8) {
            return new Response('<script>alert("Un maximum de 8 options est autorisé.");</script>', 200);
        }

        $isRequired = (bool)$request->request->get('isRequired', false);
        $inputClass = $request->request->get('inputClass', 'poll-option-input');

        return $this->render('dashboard/_poll_option_field.html.twig', [
            'count' => $count,
            'isRequired' => $isRequired,
            'inputClass' => $inputClass,
        ]);
    }

    #[Route('/poll/{optionId}/vote', name: 'app_poll_vote', methods: ['POST'])]
    public function vote(
        int $optionId,
        EntityManagerInterface $entityManager,
        MercurePublisher $mercurePublisher,
    ): Response {
        $option = $entityManager->getRepository(PollOption::class)->find($optionId);
        if (!$option) {
            return new Response('Option non trouvée.', 404);
        }

        $poll = $option->getPoll();
        $message = $poll->getMessage();
        $channel = $message->getChannel();

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        if (($channel->isPrivate() || $channel->isDm()) && !$channel->getMembers()->contains($currentUser)) {
            return new Response('Non autorisé.', 403);
        }

        $voteRepo = $entityManager->getRepository(PollVote::class);
        $existingVote = $voteRepo->findOneBy([
            'option' => $option,
            'user' => $currentUser,
        ]);

        if (!$poll->isAllowMultiple()) {
            // Find all votes by this user on this poll's options in a single query
            $userVotes = $voteRepo->createQueryBuilder('v')
                ->join('v.option', 'o')
                ->where('o.poll = :poll')
                ->andWhere('v.user = :user')
                ->setParameter('poll', $poll)
                ->setParameter('user', $currentUser)
                ->getQuery()
                ->getResult();

            $wasVotedOnTarget = false;
            foreach ($userVotes as $vote) {
                if ($vote->getOption()->getId() === $option->getId()) {
                    $wasVotedOnTarget = true;
                }
                $vote->getOption()->removeVote($vote);
                $entityManager->remove($vote);
            }

            // If the user was not voting for this specific option, create the vote
            if (!$wasVotedOnTarget) {
                $newVote = new PollVote();
                $newVote->setUser($currentUser);
                $newVote->setOption($option);
                $option->addVote($newVote);
                $entityManager->persist($newVote);
            }
        } else {
            // Multiple choices: simple toggle on this option
            if ($existingVote) {
                $option->removeVote($existingVote);
                $entityManager->remove($existingVote);
            } else {
                $newVote = new PollVote();
                $newVote->setUser($currentUser);
                $newVote->setOption($option);
                $option->addVote($newVote);
                $entityManager->persist($newVote);
            }
        }

        $entityManager->flush();

        // Refresh the message association to avoid cache issues
        $entityManager->refresh($message);

        $renderedHtml = $this->renderFeedItem($message, ['no_fade' => true]);

        $renderedHtmlOob = $this->renderView(
            'dashboard/_feed_item.html.twig',
            array_merge(
                $this->feedItemParams($message),
                ['oob' => true],
            ),
        );

        $mercurePublisher->publishToChannel($channel, $renderedHtmlOob, 'message_'.$channel->getSlug());

        return new Response($renderedHtml);
    }
}
