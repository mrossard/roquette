<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Entity\PollOption;
use App\Service\ChannelManager;
use App\Service\MessageBroadcaster;
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
    use ChannelAccessTrait;

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
        MessageBroadcaster $messageBroadcaster,
        PollManager $pollManager,
        MessageRenderer $messageRenderer,
    ): Response {
        $option = $entityManager->getRepository(PollOption::class)->find($optionId);
        if (!$option) {
            return new Response('Option non trouvée.', 404);
        }

        $poll = $option->getPoll();
        $message = $poll->getMessage();

        $this->authorizeMessageAccess($message);

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $pollManager->toggleVote($option, $currentUser);

        $renderedHtml = $messageRenderer->renderFeedItem($message, ['no_fade' => true]);
        $messageBroadcaster->broadcastMessageUpdate($message);

        return new Response($renderedHtml);
    }

    #[Route('/channel/{slug}/composer/toggle', name: 'app_composer_toggle', methods: ['GET', 'POST'])]
    public function toggleComposer(string $slug, Request $request, ChannelManager $channelManager): Response
    {
        $channel = $channelManager->findChannelBySlug($slug);
        $dto = \App\Dto\Poll\ToggleComposerDto::fromRequest($request);

        return $this->render('dashboard/_input_form.html.twig', [
            'activeChannel' => $channel,
            'pollComposerOpen' => $dto->open,
            'messageValue' => $dto->messageValue,
            'pollQuestion' => $dto->pollQuestion,
            'pollOptions' => $dto->pollOptions,
            'allowMultiple' => $dto->allowMultiple,
        ]);
    }
}
