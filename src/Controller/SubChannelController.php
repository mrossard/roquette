<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\MessageRepository;
use App\Service\ChannelManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class SubChannelController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/messages/{id}/sub-channel', name: 'app_message_create_subchannel', methods: ['POST'])]
    public function createSubChannel(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        ChannelManager $channelManager,
    ): Response {
        $parentMessage = $messageRepository->find($id);
        if (!$parentMessage) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        try {
            /** @var \App\Entity\User $currentUser */
            $currentUser = $this->getUser();
            $channel = $channelManager->createSubChannel($parentMessage, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        $this->addFlash('success', $this->translator->trans('Discussion "%channelName%" créée.', [
            '%channelName%' => $channel->getName(),
        ]));

        $url = $this->generateUrl('app_channel', ['slug' => $channel->getSlug()]);
        if ($request->headers->has('HX-Request')) {
            return new Response(null, 204, ['HX-Redirect' => $url]);
        }

        return $this->redirect($url);
    }

    #[Route('/messages/{id}/sub-channel-todo', name: 'app_message_create_subchannel_todo', methods: ['POST'])]
    public function createSubChannelTodo(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        ChannelManager $channelManager,
    ): Response {
        $parentMessage = $messageRepository->find($id);
        if (!$parentMessage) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        try {
            /** @var \App\Entity\User $currentUser */
            $currentUser = $this->getUser();
            $channel = $channelManager->createTodoListSubChannel($parentMessage, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        $this->addFlash('success', $this->translator->trans('Discussion "%channelName%" créée.', [
            '%channelName%' => $channel->getName(),
        ]));

        $url = $this->generateUrl('app_channel', ['slug' => $channel->getSlug()]);
        if ($request->headers->has('HX-Request')) {
            return new Response(null, 204, ['HX-Redirect' => $url]);
        }

        return $this->redirect($url);
    }
}
