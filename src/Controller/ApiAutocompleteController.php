<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\UserRepository;
use App\Service\CustomEmojiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ApiAutocompleteController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ChannelRepository $channelRepository,
    ) {}

    #[Route('/api/users', name: 'app_api_users', methods: ['GET'])]
    public function apiUsers(Request $request): JsonResponse
    {
        $q = (string) $request->query->get('q', '');
        $users = $this->userRepository->searchAutocomplete($q, 20);

        $data = [];
        foreach ($users as $user) {
            $data[] = [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'displayName' => $user->getDisplayName(),
                'hue' => $user->getHue(),
            ];
        }

        return new JsonResponse($data);
    }

    #[Route('/api/users-options', name: 'app_api_users_options', methods: ['GET'])]
    public function apiUsersOptions(): Response
    {
        $users = $this->userRepository->findBy([], ['displayName' => 'ASC'], 20);

        return $this->render('api/_user_options.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/api/channels', name: 'app_api_channels', methods: ['GET'])]
    public function apiChannels(Request $request): JsonResponse
    {
        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return new JsonResponse([], Response::HTTP_UNAUTHORIZED);
        }

        $q = (string) $request->query->get('q', '');
        $channels = $this->channelRepository->searchAccessibleChannelsForUser($currentUser, $q, 20);

        $data = [];
        foreach ($channels as $channel) {
            $data[] = [
                'id' => $channel->getId(),
                'name' => $channel->getName(),
                'slug' => $channel->getSlug(),
                'description' => $channel->getDescription(),
            ];
        }

        return new JsonResponse($data);
    }

    #[Route('/api/autocomplete/{type}', name: 'app_api_autocomplete', methods: ['GET'])]
    public function apiAutocomplete(string $type, Request $request, CustomEmojiService $emojiService): Response
    {
        $q = (string) $request->query->get('q', '');

        if ($type === 'custom-emojis') {
            $matchingEmojis = [];
            try {
                $result = $emojiService->list($q, 1, 100);
                foreach ($result['emojis'] as $emoji) {
                    $matchingEmojis[] = [
                        'name' => $emoji['code'],
                        'filename' => $emoji['filename'],
                    ];
                }
            } catch (\Throwable $e) {
                unset($e);
            }

            usort($matchingEmojis, static fn($a, $b) => strcmp($a['name'], $b['name']));
            $matchingEmojis = array_slice($matchingEmojis, 0, 10);

            return $this->render('api/_autocomplete_items.html.twig', [
                'type' => 'custom-emojis',
                'emojis' => $matchingEmojis,
            ]);
        }

        if ($type === 'users') {
            return $this->render('api/_autocomplete_items.html.twig', [
                'type' => 'users',
                'users' => $this->userRepository->searchAutocomplete($q, 20),
            ]);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        return $this->render('api/_autocomplete_items.html.twig', [
            'type' => 'channels',
            'channels' => $this->channelRepository->searchAccessibleChannelsForUser($currentUser, $q, 20),
        ]);
    }
}
