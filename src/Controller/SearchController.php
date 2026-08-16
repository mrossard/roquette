<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\UserChannelRead;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
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
        private readonly MessageRepository $messageRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/channels/{slug}/search', name: 'app_channel_search', methods: ['GET'])]
    public function searchInChannel(string $slug, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $activeChannel = $this->findAndAuthorizeChannel($slug, $this->channelRepository);
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

            $messageIds = array_map(static fn(Message $m) => (int) $m->getId(), $messages);
            $replyCounts = $this->messageRepository->findReplyCounts($messageIds);
            $subchannelByParentMessageId = $this->channelRepository->findSubchannelsByChannel($activeChannel);

            return $this->render('dashboard/_messages_feed.html.twig', [
                'messages' => $messages,
                'activeChannel' => $activeChannel,
                'firstUnreadMessageId' => null,
                'replyCounts' => $replyCounts,
                'subchannelByParentMessageId' => $subchannelByParentMessageId,
            ]);
        }

        $messages = $this->messageRepository->searchInChannel($activeChannel, $query);

        $messageIds = array_map(static fn(Message $m) => (int) $m->getId(), $messages);
        $replyCounts = $this->messageRepository->findReplyCounts($messageIds);
        $subchannelByParentMessageId = $this->channelRepository->findSubchannelsByChannel($activeChannel);

        return $this->render('dashboard/_messages_feed.html.twig', [
            'messages' => $messages,
            'activeChannel' => $activeChannel,
            'searchQuery' => $query,
            'firstUnreadMessageId' => null,
            'replyCounts' => $replyCounts,
            'subchannelByParentMessageId' => $subchannelByParentMessageId,
        ]);
    }

    #[Route('/search', name: 'app_global_search', methods: ['GET'])]
    public function globalSearch(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $rawQuery = trim($request->query->get('q', ''));

        if ($rawQuery === '') {
            return $this->render('dashboard/_global_search_results.html.twig', [
                'channels' => [],
                'users' => [],
                'messages' => [],
                'query' => $rawQuery,
            ]);
        }

        $authorUsername = null;
        $channelName = null;
        $hasFile = null;
        $fileType = null;
        $textQuery = $rawQuery;

        // Parse from:filter
        if (preg_match('/from:([^\s"]+|"[^"]+")/', $textQuery, $matches)) {
            $authorUsername = trim($matches[1], '"@');
            $textQuery = str_replace($matches[0], '', $textQuery);
        }

        // Parse in:filter
        if (preg_match('/in:([^\s"]+|"[^"]+")/', $textQuery, $matches)) {
            $channelName = trim($matches[1], '"#');
            $textQuery = str_replace($matches[0], '', $textQuery);
        }

        // Parse has:filter
        if (preg_match('/has:([^\s]+)/', $textQuery, $matches)) {
            $hasValue = strtolower($matches[1]);
            $hasFile = true;
            if (in_array($hasValue, ['image', 'video', 'audio', 'pdf'], strict: true)) {
                $fileType = $hasValue;
            }
            $textQuery = str_replace($matches[0], '', $textQuery);
        }

        $textQuery = trim($textQuery);

        // Fetch matches
        $channels = [];
        $users = [];
        // Only return channels and users matches if searching with a simple query (no filters)
        if (!$authorUsername && !$channelName && !$hasFile) {
            $channels = $this->channelRepository->searchByName($textQuery, $currentUser);
            $users = $this->userRepository->searchByName($textQuery);
        }

        $messages = $this->messageRepository->searchGlobal(
            $currentUser,
            $authorUsername,
            $channelName,
            $hasFile,
            $fileType,
            $textQuery,
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

        $messageIds = array_map(static fn(Message $m) => (int) $m->getId(), $messages);
        $replyCounts = $this->messageRepository->findReplyCounts($messageIds);
        $subchannelByParentMessageId = $this->channelRepository->findSubchannelsByChannel($activeChannel);

        return $this->render('dashboard/_messages_feed.html.twig', [
            'messages' => $messages,
            'activeChannel' => $activeChannel,
            'firstUnreadMessageId' => null,
            'unreadFilterActive' => true,
            'searchQuery' => $query !== '' ? $query : null,
            'replyCounts' => $replyCounts,
            'subchannelByParentMessageId' => $subchannelByParentMessageId,
        ]);
    }
}
