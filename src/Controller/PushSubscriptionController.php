<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PushSubscription;
use App\Repository\PushSubscriptionRepository;
use App\Service\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class PushSubscriptionController extends AbstractController
{
    #[Route('/push/subscribe', name: 'app_push_subscribe', methods: ['POST'])]
    public function subscribe(
        Request $request,
        PushSubscriptionRepository $subscriptionRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $data = json_decode($request->getContent(), true);

        if (
            $data === null
            || !is_string($data['endpoint'] ?? null)
            || !is_string($data['keys']['p256dh'] ?? null)
            || !is_string($data['keys']['auth'] ?? null)
        ) {
            return new JsonResponse(['error' => 'Invalid subscription data'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->getUser();

        $existing = $subscriptionRepository->findOneByUserAndEndpoint($user, $data['endpoint']);
        if ($existing !== null) {
            $existing->setPublicKey($data['keys']['p256dh']);
            $existing->setAuthToken($data['keys']['auth']);
            $existing->setUserAgent($request->headers->get('User-Agent'));
            $entityManager->flush();

            return new JsonResponse(['status' => 'updated'], Response::HTTP_OK);
        }

        $subscription = new PushSubscription();
        $subscription->setUser($user);
        $subscription->setEndpoint($data['endpoint']);
        $subscription->setPublicKey($data['keys']['p256dh']);
        $subscription->setAuthToken($data['keys']['auth']);
        $subscription->setUserAgent($request->headers->get('User-Agent'));

        $entityManager->persist($subscription);
        $entityManager->flush();

        return new JsonResponse(['status' => 'subscribed'], Response::HTTP_CREATED);
    }

    #[Route('/push/unsubscribe', name: 'app_push_unsubscribe', methods: ['POST'])]
    public function unsubscribe(
        Request $request,
        PushSubscriptionRepository $subscriptionRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $data = json_decode($request->getContent(), true);

        if ($data === null || !is_string($data['endpoint'] ?? null)) {
            return new JsonResponse(['error' => 'Missing endpoint'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->getUser();

        $subscription = $subscriptionRepository->findOneByUserAndEndpoint($user, $data['endpoint']);
        if ($subscription !== null) {
            $entityManager->remove($subscription);
            $entityManager->flush();
        }

        return new JsonResponse(['status' => 'unsubscribed'], Response::HTTP_OK);
    }

    #[Route('/push/public-key', name: 'app_push_public_key', methods: ['GET'])]
    public function publicKey(PushNotificationService $pushNotificationService): Response
    {
        return new JsonResponse([
            'publicKey' => $pushNotificationService->getPublicKey(),
        ]);
    }
}
