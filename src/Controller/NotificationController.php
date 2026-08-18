<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Entity\User;
use App\Entity\UserChannelRead;
use App\Service\ChannelManager;
use App\Service\MercurePublisher;
use App\Service\ReadTrackingService;
use App\Service\TypingIndicatorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class NotificationController extends AbstractController
{
    use ChannelAccessTrait;

    public function __construct(
        private readonly ChannelManager $channelManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly ReadTrackingService $readTrackingService,
        private readonly TypingIndicatorService $typingIndicatorService,
        private readonly MercurePublisher $mercurePublisher,
    ) {}

    #[Route('/channels/{slug}/read', name: 'app_channel_read', methods: ['POST'])]
    public function markAsRead(string $slug): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $activeChannel = $this->findAuthorizedChannel($slug, $this->channelManager);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        $this->readTrackingService->markChannelAsRead($currentUser, $activeChannel);

        return new Response('', 204);
    }

    #[Route('/channels/{slug}/toggle-notifications', name: 'app_channel_toggle_notifications', methods: ['POST'])]
    public function toggleNotifications(string $slug): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $channel = $this->findAuthorizedChannel($slug, $this->channelManager);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        $ucrRepo = $this->entityManager->getRepository(UserChannelRead::class);
        $ucr = $ucrRepo->findOneBy(['user' => $currentUser, 'channel' => $channel]);
        if (!$ucr) {
            $ucr = new UserChannelRead();
            $ucr->setUser($currentUser);
            $ucr->setChannel($channel);
            $this->entityManager->persist($ucr);
        }

        $currentStatus = $ucr->isNotificationsEnabled();
        if ($currentStatus === null) {
            $currentStatus = $channel->isDm();
        }

        $newStatus = !$currentStatus;
        $ucr->setNotificationsEnabled($newStatus);
        $this->entityManager->flush();

        return $this->render('dashboard/_notification_toggle.html.twig', [
            'activeChannel' => $channel,
            'notificationsEnabled' => $newStatus,
        ]);
    }

    #[Route('/channel/{slug}/typing', name: 'app_channel_typing_legacy', methods: ['POST'])]
    #[Route('/channels/{slug}/typing', name: 'app_channel_typing', methods: ['POST'])]
    public function typing(string $slug, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $activeChannel = $this->findAuthorizedChannel($slug, $this->channelManager);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        $isTyping = filter_var($request->request->get('isTyping', false), FILTER_VALIDATE_BOOLEAN);
        if ($request->headers->get('Content-Type') === 'application/json') {
            $data = json_decode($request->getContent(), true);
            $isTyping = filter_var($data['isTyping'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        $isTyping
            ? $this->typingIndicatorService->startTyping($activeChannel, $currentUser)
            : $this->typingIndicatorService->stopTyping($activeChannel, $currentUser);
        $this->mercurePublisher->publishToChannel($activeChannel, 'ping', 'typing_' . $activeChannel->getSlug());

        return $this->typingIndicator($slug);
    }

    #[Route('/channel/{slug}/typing-indicator', name: 'app_channel_typing_indicator_legacy', methods: ['GET'])]
    #[Route('/channels/{slug}/typing-indicator', name: 'app_channel_typing_indicator', methods: ['GET'])]
    public function typingIndicator(string $slug): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $activeChannel = $this->findAuthorizedChannel($slug, $this->channelManager);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        $names = $this->typingIndicatorService->getTypingUsers($activeChannel, $currentUser);

        return $this->render('dashboard/_typing_indicator.html.twig', [
            'typingUsers' => $names,
            'activeChannel' => $activeChannel,
        ]);
    }
}
