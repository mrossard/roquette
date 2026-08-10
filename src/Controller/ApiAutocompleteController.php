<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Channel;
use App\Entity\User;
use App\Service\CustomEmojiService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;

#[IsGranted('ROLE_USER')]
final class ApiAutocompleteController extends AbstractController
{
    #[Route('/api/users', name: 'app_api_users', methods: ['GET'])]
    public function apiUsers(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $q = $request->query->get('q', '');
        $qb = $entityManager->getRepository(User::class)->createQueryBuilder('u');
        if ($q !== '') {
            $qb->where('LOWER(u.username) LIKE :q OR LOWER(u.displayName) LIKE :q')->setParameter(
                'q',
                '%' . mb_strtolower($q) . '%',
            );
        }
        $users = $qb->setMaxResults(20)->getQuery()->getResult();

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
    public function apiUsersOptions(EntityManagerInterface $entityManager): Response
    {
        $users = $entityManager->getRepository(User::class)->findBy([], ['displayName' => 'ASC'], 20);

        return $this->render('api/_user_options.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/api/channels', name: 'app_api_channels', methods: ['GET'])]
    public function apiChannels(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return new JsonResponse([], Response::HTTP_UNAUTHORIZED);
        }

        $q = $request->query->get('q', '');
        $qb = $entityManager
            ->getRepository(Channel::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.members', 'm')
            ->where('c.isDm = false')
            ->andWhere('c.isPrivate = false OR m.id = :userId')
            ->setParameter('userId', $currentUser->getId());

        if ($q !== '') {
            $qb->andWhere('LOWER(c.name) LIKE :q OR LOWER(c.slug) LIKE :q')->setParameter(
                'q',
                '%' . mb_strtolower($q) . '%',
            );
        }

        $channels = $qb->orderBy('LOWER(c.name)', 'ASC')->setMaxResults(20)->getQuery()->getResult();

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
    public function apiAutocomplete(
        string $type,
        Request $request,
        EntityManagerInterface $entityManager,
        CacheInterface $cache,
        CustomEmojiService $emojiService,
    ): Response {
        $q = $request->query->get('q', '');

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
            $qb = $entityManager->getRepository(User::class)->createQueryBuilder('u');
            if ($q !== '') {
                $qb->where('LOWER(u.username) LIKE :q OR LOWER(u.displayName) LIKE :q')->setParameter(
                    'q',
                    '%' . mb_strtolower($q) . '%',
                );
            }

            return $this->render('api/_autocomplete_items.html.twig', [
                'type' => 'users',
                'users' => $qb->setMaxResults(20)->getQuery()->getResult(),
            ]);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $qb = $entityManager
            ->getRepository(Channel::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.members', 'm')
            ->where('c.isDm = false')
            ->andWhere('c.isPrivate = false OR m.id = :userId')
            ->setParameter('userId', $currentUser->getId());

        if ($q !== '') {
            $qb->andWhere('LOWER(c.name) LIKE :q OR LOWER(c.slug) LIKE :q')->setParameter(
                'q',
                '%' . mb_strtolower($q) . '%',
            );
        }

        return $this->render('api/_autocomplete_items.html.twig', [
            'type' => 'channels',
            'channels' => $qb->orderBy('LOWER(c.name)', 'ASC')->setMaxResults(20)->getQuery()->getResult(),
        ]);
    }
}
