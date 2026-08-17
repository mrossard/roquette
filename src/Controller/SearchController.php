<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Entity\User;
use App\Entity\UserChannelRead;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\ChannelManager;
use App\Service\MessageFeedContextService;
use App\Service\MessageSearchParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class SearchController extends AbstractController
{
    use ChannelAccessTrait;

    public function __construct(
        private readonly ChannelRepository $channelRepository,
        private readonly ChannelManager $channelManager,
        private readonly MessageRepository $messageRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageFeedContextService $feedContextService,
        private readonly MessageSearchParser $searchParser,
    ) {}

    #[Route('/channels/{slug}/search', name: 'app_channel_search', methods: ['GET'])]
    public function searchInChannel(string $slug, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $activeChannel = $this->findAuthorizedChannel($slug, $this->channelManager);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }

        $query = trim($request->query->get('q', ''));
        $unread = $request->query->getBoolean('unread', false);

        if ($unread) {
            return $this->renderUnreadMessages($activeChannel, $currentUser, $query);
        }

        if ($query === '') {
            $messages = $this->messageRepository->findLatestInChannel($activeChannel, 50);
            $messages = array_reverse($messages);

            $feedContext = $this->feedContextService->buildFeedContext($activeChannel, $messages);

            return $this->render('dashboard/_messages_feed.html.twig', array_merge([
                'messages' => $messages,
                'activeChannel' => $activeChannel,
                'firstUnreadMessageId' => null,
            ], $feedContext));
        }

        $messages = $this->messageRepository->searchInChannel($activeChannel, $query);

        $feedContext = $this->feedContextService->buildFeedContext($activeChannel, $messages);

        return $this->render('dashboard/_messages_feed.html.twig', array_merge([
            'messages' => $messages,
            'activeChannel' => $activeChannel,
            'searchQuery' => $query,
            'firstUnreadMessageId' => null,
        ], $feedContext));
    }

    #[Route('/search', name: 'app_global_search', methods: ['GET'])]
    public function globalSearch(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $rawQuery = trim($request->query->get('q', ''));

        $parsed = $this->searchParser->parse($rawQuery);
        if ($parsed->isEmpty()) {
            return $this->render('dashboard/_global_search_results.html.twig', [
                'channels' => [],
                'users' => [],
                'messages' => [],
                'query' => $rawQuery,
            ]);
        }

        // Fetch matches
        $channels = [];
        $users = [];
        // Only return channels and users matches if searching with a simple query (no filters)
        if (!$parsed->hasFilters()) {
            $channels = $this->channelRepository->searchByName($parsed->textQuery, $currentUser);
            $users = $this->userRepository->searchByName($parsed->textQuery);
        }

        $messages = $this->messageRepository->searchGlobal(
            $currentUser,
            $parsed->authorUsername,
            $parsed->channelName,
            $parsed->hasFile,
            $parsed->fileType,
            $parsed->textQuery,
        );

        return $this->render('dashboard/_global_search_results.html.twig', [
            'channels' => $channels,
            'users' => $users,
            'messages' => $messages,
            'query' => $rawQuery,
        ]);
    }

    private function renderUnreadMessages(
        \App\Entity\Channel $activeChannel,
        User $currentUser,
        string $query,
    ): Response {
        $ucrRepo = $this->entityManager->getRepository(UserChannelRead::class);
        /** @var UserChannelRead|null $activeRead */
        $activeRead = $ucrRepo->findOneBy(['user' => $currentUser, 'channel' => $activeChannel]);
        $lastReadMessageId = $activeRead?->getLastReadMessage()?->getId();

        $messages = $this->messageRepository->findUnreadInChannel($activeChannel, $currentUser, $lastReadMessageId);

        if ($query !== '') {
            $messages = array_filter(
                $messages,
                static fn($m) => (
                    mb_strpos(mb_strtolower($m->getContent(), 'UTF-8'), mb_strtolower($query, 'UTF-8')) !== false
                ),
            );
        }

        $feedContext = $this->feedContextService->buildFeedContext($activeChannel, $messages);

        return $this->render('dashboard/_messages_feed.html.twig', array_merge([
            'messages' => $messages,
            'activeChannel' => $activeChannel,
            'firstUnreadMessageId' => null,
            'unreadFilterActive' => true,
            'searchQuery' => $query !== '' ? $query : null,
        ], $feedContext));
    }
}
