<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PollOption;
use App\Service\MercurePublisher;
use App\Service\MessageRenderer;
use App\Service\PollManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class PollController extends AbstractController
{
    #[Route('/poll/option-field', name: 'app_poll_option_field', methods: ['POST'])]
    public function getOptionField(Request $request): Response
    {
        $count = (int) $request->request->get('count', 0);
        if ($count >= 8) {
            return new Response('<script>alert("Un maximum de 8 options est autorisé.");</script>', 200);
        }

        $isRequired = (bool) $request->request->get('isRequired', false);
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
        PollManager $pollManager,
        MessageRenderer $messageRenderer,
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

        $pollManager->toggleVote($option, $currentUser);

        $renderedHtml = $messageRenderer->renderFeedItem($message, ['no_fade' => true]);

        $renderedHtmlOob = $this->renderView('dashboard/_feed_item.html.twig', array_merge(
            $messageRenderer->feedItemParams($message),
            ['oob' => true],
        ));

        $mercurePublisher->publishToChannel($channel, $renderedHtmlOob, 'message_' . $channel->getSlug());

        return new Response($renderedHtml);
    }

    #[Route('/channel/{slug}/composer/toggle', name: 'app_composer_toggle', methods: ['GET', 'POST'])]
    public function toggleComposer(string $slug, Request $request, EntityManagerInterface $entityManager): Response
    {
        $channel = $entityManager->getRepository(\App\Entity\Channel::class)->findOneBy(['slug' => $slug]);
        if (!$channel) {
            throw $this->createNotFoundException('Canal non trouvé.');
        }

        $open = (bool) ($request->query->get('open') ?? $request->request->get('open', false));
        $messageValue = $request->query->get('message') ?? $request->request->get('message', '');
        $pollQuestion = $request->query->get('poll_question') ?? $request->request->get('poll_question', '');

        $pollOptions = $request->query->all('poll_options');
        if ($pollOptions === []) {
            $pollOptions = $request->request->all('poll_options');
        }

        $allowMultiple = (bool) (
            $request->query->get('allow_multiple') ?? $request->request->get('allow_multiple', false)
        );

        // If we are closing, clear the poll fields
        if (!$open) {
            $pollQuestion = '';
            $pollOptions = [];
            $allowMultiple = false;
        }

        return $this->render('dashboard/_input_form.html.twig', [
            'activeChannel' => $channel,
            'pollComposerOpen' => $open,
            'messageValue' => $messageValue,
            'pollQuestion' => $pollQuestion,
            'pollOptions' => $pollOptions,
            'allowMultiple' => $allowMultiple,
        ]);
    }
}
