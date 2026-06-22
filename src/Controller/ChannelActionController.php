<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Controller\Trait\MessageRendererTrait;
use App\Entity\Channel;
use App\Entity\ChannelExport;
use App\Entity\User;
use App\Entity\UserChannelRead;
use App\Enum\AuditAction;
use App\Message\GenerateExportMessage;
use App\Repository\ChannelRepository;
use App\Repository\InvitationRepository;
use App\Repository\MessageRepository;
use App\Service\AuditLoggerService;
use App\Service\ChannelExportService;
use App\Service\ChannelManager;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class ChannelActionController extends AbstractController
{
    use MessageRendererTrait;
    use ChannelAccessTrait;

    public function __construct(
        private TranslatorInterface $translator,
        private ChannelManager $channelManager,
    ) {}

    #[Route('/channels/create', name: 'app_channel_create', methods: ['POST'])]
    public function createChannel(Request $request, ChannelManager $channelManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $name = trim($request->request->get('name', ''));
        $description = trim($request->request->get('description', ''));

        if ($name === '') {
            $this->addFlash('error', $this->translator->trans('Le nom du canal ne peut pas être vide.'));

            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $channel = $channelManager->create(
                $name,
                $description,
                [
                    'isPrivate' => $request->request->getBoolean('isPrivate', false),
                    'groupIdentifier' => $request->request->get('groupIdentifier', ''),
                    'isGroupChannel' => $request->request->getBoolean('isGroupChannel', false),
                    'isTodoList' => $request->request->getBoolean('isTodoList', false),
                    'retentionMonths' => $request->request->get('messageRetentionMonths'),
                ],
                $currentUser,
            );
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
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $redirectSlug = $channelManager->delete($channel, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
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
        InvitationRepository $invitationRepository,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            return new Response($this->translator->trans('Canal non trouvé.'), 404);
        }

        if ($currentUser->isChannelFavorite($channel)) {
            $currentUser->removeFavoriteChannel($channel);
        } else {
            $currentUser->addFavoriteChannel($channel);
        }

        $entityManager->flush();

        if ($request->headers->has('HX-Request')) {
            $channels = $channelRepository->findAllForUser($currentUser);
            $ucrRepo = $entityManager->getRepository(UserChannelRead::class);
            $unreadCounts = $ucrRepo->getUnreadCounts($currentUser);
            $pendingInvitations = $invitationRepository->findPendingForUser($currentUser);

            $currentUrl = $request->headers->get('HX-Current-URL');
            $activeChannel = null;
            if ($currentUrl) {
                $path = parse_url($currentUrl, PHP_URL_PATH);
                if (preg_match('#^/channels/([a-z0-9-]+)$#', $path, $matches)) {
                    $activeChannel = $channelRepository->findOneBy(['slug' => $matches[1]]);
                }
            }

            $subChannelsByParent = $this->buildSubChannelsByParent($channels);
            $lastMessages = $messageRepository->findLastMessagesForChannels(array_map(
                static fn(Channel $c) => $c->getId(),
                $channels,
            ));

            $sidebarHtml = $this->renderView('dashboard/_sidebar.html.twig', [
                'channels' => $channels,
                'unreadCounts' => $unreadCounts,
                'activeChannel' => $activeChannel,
                'pendingInvitations' => $pendingInvitations,
                'subChannelsByParent' => $subChannelsByParent,
                'lastMessages' => $lastMessages,
            ]);

            $sidebarHtml = preg_replace(
                '/<section class="card glass-panel sidebar-panel" id="sidebar-panel">/',
                '<section class="card glass-panel sidebar-panel" id="sidebar-panel" hx-swap-oob="true">',
                $sidebarHtml,
                1,
            );

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
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return $this->redirectToRoute('app_dashboard');
        }

        $retention = $request->request->get('messageRetentionMonths');
        $retentionVal = null;
        if ($retention !== null && $retention !== '') {
            $retentionVal = (int) $retention;
        }

        try {
            $channelManager->updateRetention($channel, $retentionVal, $currentUser);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
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
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return $this->redirectToRoute('app_dashboard');
        }

        $name = trim($request->request->get('name', ''));
        $description = trim($request->request->get('description', ''));

        if ($name === '') {
            $this->addFlash('error', $this->translator->trans('Le nom du canal ne peut pas être vide.'));

            return $this->redirectToRoute('app_channel', ['slug' => $slug]);
        }

        try {
            $channelManager->update(
                $channel,
                $name,
                $description,
                [
                    'isTodoList' => $request->request->getBoolean('isTodoList', false),
                    'retentionMonths' => $request->request->get('messageRetentionMonths'),
                    'administratorIds' => $request->request->all('administrators'),
                ],
                $currentUser,
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }

        $this->addFlash('success', $this->translator->trans('Les paramètres du canal ont été modifiés.'));

        return $this->redirectToRoute('app_channel', ['slug' => $channel->getSlug()]);
    }

    #[Route('/channels/{slug}/export', name: 'app_channel_export', methods: ['GET'])]
    public function exportChannel(
        string $slug,
        ChannelRepository $channelRepository,
        MessageBusInterface $messageBus,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $channel = $this->findAndAuthorizeChannel($slug, $channelRepository);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$channel->isAdministrator($currentUser)) {
            throw $this->createAccessDeniedException($this->translator->trans(
                'Non autorisé à exporter l\'historique de ce canal.',
            ));
        }

        $messageBus->dispatch(new GenerateExportMessage($channel->getId(), $currentUser->getId()));

        return $this->render('dashboard/export_requested.html.twig', [
            'channel' => $channel,
        ]);
    }

    #[Route('/exports/{id}/download', name: 'app_export_download', methods: ['GET'])]
    public function downloadExport(
        ChannelExport $export,
        FileUploadService $fileUploadService,
        AuditLoggerService $auditLogger,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if (!$this->isGranted('ROLE_ADMIN') && $export->getExportedBy() !== $currentUser) {
            throw $this->createAccessDeniedException($this->translator->trans(
                'Non autorisé à télécharger cet export.',
            ));
        }

        if (!$fileUploadService->exists($export->getFilePath())) {
            throw $this->createNotFoundException($this->translator->trans(
                'Le fichier d\'export n\'existe pas dans le stockage.',
            ));
        }

        $auditLogger->log(AuditAction::EXPORT_DOWNLOAD, $currentUser, [
            'export_id' => $export->getId(),
            'file_name' => $export->getFileName(),
            'channel_name' => $export->getChannelName(),
        ]);

        $contentType = str_ends_with($export->getFileName(), '.tar') ? 'application/x-tar' : 'application/zip';

        return new StreamedResponse(
            static function () use ($fileUploadService, $export) {
                $fileStream = $fileUploadService->readStream($export->getFilePath());
                if ($fileStream) {
                    fpassthru($fileStream);
                    fclose($fileStream);
                }
            },
            Response::HTTP_OK,
            [
                'Content-Type' => $contentType,
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT,
                    $export->getFileName(),
                ),
            ],
        );
    }

    /** @param Channel[] $channels */
    private function buildSubChannelsByParent(array $channels): array
    {
        return $this->channelManager->buildSubChannelsByParent($channels);
    }
}
