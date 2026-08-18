<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Entity\ChannelExport;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Message\GenerateExportMessage;
use App\Service\AuditLoggerService;
use App\Service\ChannelManager;
use App\Service\FileStreamResponseFactory;
use App\Service\FileUploadService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class ChannelExportController extends AbstractController
{
    use ChannelAccessTrait;

    public function __construct(
        private readonly ChannelManager $channelManager,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/channels/{slug}/export', name: 'app_channel_export', methods: ['POST'])]
    public function exportChannel(string $slug, MessageBusInterface $messageBus): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $channel = $this->findAuthorizedChannel($slug, $this->channelManager, 'MANAGE');
        } catch (HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
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
        FileStreamResponseFactory $fileResponseFactory,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if (!$this->isGranted('ROLE_ADMIN') && $export->getExportedBy() !== $currentUser) {
            throw $this->createAccessDeniedException($this->translator->trans(
                'Non autorisé à télécharger cet export.',
            ));
        }

        $auditLogger->log(AuditAction::EXPORT_DOWNLOAD, $currentUser, [
            'export_id' => $export->getId(),
            'file_name' => $export->getFileName(),
            'channel_name' => $export->getChannelName(),
        ]);

        return $fileResponseFactory->createExportDownloadResponse($export, $fileUploadService);
    }
}
