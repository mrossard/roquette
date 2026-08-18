<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Dto\Channel\CreateChannelDto;
use App\Dto\Channel\UpdateChannelDto;
use App\Entity\User;
use App\Message\LlmQueryMessage;
use App\Repository\ChannelRepository;
use App\Repository\WorkspaceRepository;
use App\Service\ChannelManager;
use App\Service\SidebarDataProvider;
use App\Service\WorkspaceManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class ChannelActionController extends AbstractController
{
    use ChannelAccessTrait;
    use HxControllerTrait;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ChannelManager $channelManager,
        private readonly WorkspaceManager $workspaceManager,
        private readonly SidebarDataProvider $sidebarDataProvider,
        private readonly \App\Service\WorkspaceContext $workspaceContext,
    ) {}

    #[Route('/channels/{slug}/summarize', name: 'app_channel_summarize_modal', methods: ['POST'])]
    public function summarizeChannel(
        string $slug,
        Request $request,
        MessageBusInterface $messageBus,
        \App\Service\MercurePublisher $mercurePublisher,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $channel = $this->findAuthorizedChannel($slug, $this->channelManager);

        $helpMessageId = 'summary-modal-stream-' . uniqid();
        $promptText = 'résume le canal ' . $channel->getName();
        $workspaceId = $this->workspaceContext->getCurrentWorkspaceOrPublic()?->getId();

        $messageBus->dispatch(new LlmQueryMessage(
            question: $promptText,
            userId: $currentUser->getId(),
            channelSlug: $channel->getSlug(),
            helpMessageId: $helpMessageId,
            intent: \App\Ai\AssistantIntent::Summarize,
            workspaceId: $workspaceId,
        ));

        $topic = $mercurePublisher->getUserTopic($currentUser);

        return $this->render('dashboard/_channel_summary_modal.html.twig', [
            'channel' => $channel,
            'topic' => $topic,
            'helpMessageId' => $helpMessageId,
        ]);
    }

    #[Route('/channels/create', name: 'app_channel_create', methods: ['POST'])]
    public function createChannel(
        Request $request,
        ChannelManager $channelManager,
        WorkspaceRepository $workspaceRepository,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $workspaceId = $request->request->getInt('workspaceId', 0);
        $workspace = null;
        if ($workspaceId > 0) {
            $workspace = $workspaceRepository->find($workspaceId);
            if (!$workspace || !$this->isGranted('VIEW', $workspace)) {
                $this->addFlash('error', $this->translator->trans('Vous ne pouvez pas créer un canal dans cet espace de travail.'));

                return $this->redirectToRoute('app_dashboard');
            }
        }

        $dto = CreateChannelDto::fromRequest($request, $workspace);
        if (!$dto->isValid()) {
            $this->addFlash('error', $this->translator->trans('Le nom du canal ne peut pas être vide.'));

            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $channel = $channelManager->create($dto, $currentUser);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_dashboard');
        }

        return $this->redirectToRoute('app_channel', ['slug' => $channel->getSlug()]);
    }

    #[Route('/channels/{slug}/delete', name: 'app_channel_delete', methods: ['POST'])]
    public function deleteChannel(string $slug, ChannelManager $channelManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $channel = $channelManager->findChannelBySlug($slug);
        } catch (NotFoundHttpException) {
            return $this->redirectToRoute('app_dashboard');
        }

        $this->denyAccessUnlessGranted('DELETE', $channel);

        try {
            $redirectSlug = $channelManager->delete($channel, $currentUser);
        } catch (HttpExceptionInterface $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }

        $this->addFlash('success', $this->translator->trans('Le canal "%channelName%" a été supprimé.', [
            '%channelName%' => $channel->getName(),
        ]));

        if ($redirectSlug !== 'dashboard') {
            return $this->redirectToRoute('app_channel', ['slug' => $redirectSlug]);
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/channels/reorder', name: 'app_channels_reorder', methods: ['POST'])]
    public function reorderChannels(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $order = $data['order'] ?? null;
        if (!is_array($order)) {
            $order = $request->request->all('order');
            if ($order === null || $order === '') {
                $order = $request->request->all();
                if (is_array($order) && array_key_exists('order', $order)) {
                    $order = $order['order'];
                }
            }
        }

        if (is_array($order)) {
            $order = array_map('intval', $order);
            $currentUser->setChannelOrder($order);
            $entityManager->flush();

            return $this->json(['success' => true]);
        }

        return $this->json(['error' => $this->translator->trans('Données invalides.')], 400);
    }

    #[Route('/channels/{slug}/favorite', name: 'app_channel_favorite_toggle', methods: ['POST'])]
    public function toggleFavorite(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $channel = $this->channelManager->findChannelBySlug($slug);

        $currentUser->isChannelFavorite($channel)
            ? $currentUser->removeFavoriteChannel($channel)
            : $currentUser->addFavoriteChannel($channel);

        $entityManager->flush();

        if ($request->headers->has('HX-Request')) {
            $sidebarData = $this->sidebarDataProvider->getSidebarData($currentUser);
            $channels = $sidebarData['channels'];

            $activeChannel = $this->findActiveChannelFromHxRequest($request, $channelRepository);

            $sidebarHtml = $this->renderView('dashboard/_sidebar.html.twig', array_merge([
                'activeChannel' => $activeChannel,
                'oob' => true,
            ], $sidebarData));

            $html = $sidebarHtml;

            $isMember = $activeChannel !== null && in_array($activeChannel, $channels, true);
            if ($activeChannel && $isMember) {
                $html .=
                    "\n"
                    . $this->renderView('dashboard/_favorite_button_oob.html.twig', [
                        'activeChannel' => $activeChannel,
                        'isMember' => true,
                    ]);
            }

            return new Response($html);
        }

        return new Response(null, 204, ['HX-Refresh' => 'true']);
    }

    #[Route('/channels/{slug}/retention', name: 'app_channel_update_retention', methods: ['POST'])]
    public function updateRetention(string $slug, Request $request, ChannelManager $channelManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $channel = $channelManager->findChannelBySlug($slug);
        } catch (NotFoundHttpException) {
            return $this->redirectToRoute('app_dashboard');
        }

        $this->denyAccessUnlessGranted('EDIT', $channel);

        $retention = $request->request->get('messageRetentionMonths');
        $retentionVal = null;
        if ($retention !== null && $retention !== '') {
            $retentionVal = (int) $retention;
        }

        try {
            $channelManager->updateRetention($channel, $retentionVal, $currentUser);
        } catch (HttpExceptionInterface $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }

        $this->addFlash('success', $this->translator->trans('La durée de rétention du canal "%channelName%" a été mise à jour.', [
            '%channelName%' => $channel->getName(),
        ]));

        return new Response(null, 204, ['HX-Refresh' => 'true']);
    }

    #[Route('/channels/{slug}/edit', name: 'app_channel_edit', methods: ['POST'])]
    public function editChannel(string $slug, Request $request, ChannelManager $channelManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $channel = $channelManager->findChannelBySlug($slug);
        } catch (NotFoundHttpException) {
            return $this->redirectToRoute('app_dashboard');
        }

        $this->denyAccessUnlessGranted('EDIT', $channel);

        $name = trim($request->request->get('name', ''));
        $description = trim($request->request->get('description', ''));

        if ($name === '') {
            $this->addFlash('error', $this->translator->trans('Le nom du canal ne peut pas être vide.'));

            return $this->redirectToRoute('app_channel', ['slug' => $slug]);
        }

        try {
            $channelManager->update(
                $channel,
                UpdateChannelDto::fromNameDescriptionAndExtra(
                    $name,
                    $description,
                    [
                        'isTodoList' => $request->request->getBoolean('isTodoList', false),
                        'retentionMonths' => $request->request->get('messageRetentionMonths'),
                        'administratorIds' => $request->request->all('administrators'),
                    ],
                ),
                $currentUser,
            );
        } catch (HttpExceptionInterface $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }

        $this->addFlash('success', $this->translator->trans('Les paramètres du canal ont été modifiés.'));

        return $this->redirectToRoute('app_channel', ['slug' => $channel->getSlug()]);
    }
}
