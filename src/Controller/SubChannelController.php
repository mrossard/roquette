<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\HxControllerTrait;
use App\Repository\MessageRepository;
use App\Service\SubChannelManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class SubChannelController extends AbstractController
{
    use HxControllerTrait;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/messages/{id}/sub-channel', name: 'app_message_create_subchannel', methods: ['POST'])]
    public function createSubChannel(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        SubChannelManager $subChannelManager,
    ): Response {
        return $this->handleSubChannelCreation(
            $id,
            $request,
            $messageRepository,
            $subChannelManager->createSubChannel(...),
        );
    }

    #[Route('/messages/{id}/sub-channel-todo', name: 'app_message_create_subchannel_todo', methods: ['POST'])]
    public function createSubChannelTodo(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        SubChannelManager $subChannelManager,
    ): Response {
        return $this->handleSubChannelCreation(
            $id,
            $request,
            $messageRepository,
            $subChannelManager->createTodoListSubChannel(...),
        );
    }

    /**
     * @param callable(\App\Entity\Message, \App\Entity\User): \App\Entity\Channel $creator
     */
    private function handleSubChannelCreation(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        callable $creator,
    ): Response {
        $parentMessage = $messageRepository->find($id);
        if (!$parentMessage) {
            return new Response($this->translator->trans('Message non trouvé.'), Response::HTTP_NOT_FOUND);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        try {
            $channel = $creator($parentMessage, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        $this->addFlash('success', $this->translator->trans('Discussion "%channelName%" créée.', [
            '%channelName%' => $channel->getName(),
        ]));

        $url = $this->generateUrl('app_channel', ['slug' => $channel->getSlug()]);

        return $this->redirectOrHxRedirect($request, $url);
    }
}
