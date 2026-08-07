<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Channel;
use App\Entity\User;
use App\Entity\Webhook;
use App\Repository\ChannelRepository;
use App\Repository\WebhookRepository;
use App\Service\WebhookManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class WebhookController extends AbstractController
{
    private const MAX_PAYLOAD_SIZE = 100_000;

    #[Route('/api/webhooks/incoming/{token}', name: 'app_webhook_incoming', methods: ['POST'])]
    public function incoming(
        #[\SensitiveParameter]
        string $token,
        Request $request,
        WebhookRepository $webhookRepository,
        WebhookManager $webhookManager,
        RateLimiterFactoryInterface $webhookApiLimiter,
    ): Response {
        // The token can be provided via the URL (legacy) or the X-Webhook-Token header.
        $headerToken = $request->headers->get('X-Webhook-Token');
        if ($headerToken !== null && $headerToken !== '') {
            $token = $headerToken;
        }

        $limiter = $webhookApiLimiter->create($token);
        if (false === $limiter->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => 'Too many requests'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $contentLength = (int) $request->headers->get('Content-Length', '0');
        if ($contentLength > self::MAX_PAYLOAD_SIZE) {
            return new JsonResponse(['error' => 'Payload too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $webhook = $webhookRepository->findOneBy(['token' => $token]);

        if (!$webhook) {
            return new JsonResponse(['error' => 'Webhook not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$webhook->isActive()) {
            return new JsonResponse(['error' => 'Webhook is inactive'], Response::HTTP_FORBIDDEN);
        }

        $rawBody = $request->getContent();
        if (strlen($rawBody) > self::MAX_PAYLOAD_SIZE) {
            return new JsonResponse(['error' => 'Payload too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $data = json_decode($rawBody, true) ?? [];

        $content = $data['text'] ?? $data['content'] ?? null;
        if (null === $content || trim((string) $content) === '') {
            return new JsonResponse([
                'error' => 'Missing message content ("text" or "content")',
            ], Response::HTTP_BAD_REQUEST);
        }

        $customName = $data['username'] ?? $data['customAuthorName'] ?? null;
        $customAvatar = $data['avatar_url'] ?? $data['customAuthorAvatar'] ?? null;

        $message = $webhookManager->processIncomingWebhook(
            $webhook,
            (string) $content,
            $customName ? (string) $customName : null,
            $customAvatar ? (string) $customAvatar : null,
        );

        return new JsonResponse([
            'success' => true,
            'message_id' => $message->getId(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/channels/{slug}/webhooks/create', name: 'app_webhook_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        WebhookRepository $webhookRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $channel = $channelRepository->findOneBy(['slug' => $slug]);

        if (!$channel) {
            return new Response('Canal non trouvé', Response::HTTP_NOT_FOUND);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$channel->isAdministrator($currentUser)) {
            return new Response('Accès refusé', Response::HTTP_FORBIDDEN);
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            return new Response('Le nom du webhook est requis', Response::HTTP_BAD_REQUEST);
        }

        $webhook = new Webhook();
        $webhook->setName($name);
        $webhook->setChannel($channel);
        $webhook->setCreator($currentUser);

        $entityManager->persist($webhook);
        $entityManager->flush();

        return $this->renderWebhooksResponse($channel, $webhookRepository);
    }

    #[Route('/webhooks/{id}/toggle', name: 'app_webhook_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function toggle(
        int $id,
        WebhookRepository $webhookRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $webhook = $webhookRepository->find($id);
        if (!$webhook) {
            return new Response('Webhook non trouvé', Response::HTTP_NOT_FOUND);
        }

        $channel = $this->getChannelIfAdmin($webhook);
        if (!$channel) {
            return new Response('Accès refusé ou canal introuvable', Response::HTTP_FORBIDDEN);
        }

        $webhook->setIsActive(!$webhook->isActive());
        $entityManager->flush();

        return $this->renderWebhooksResponse($channel, $webhookRepository);
    }

    #[Route('/webhooks/{id}/delete', name: 'app_webhook_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(
        int $id,
        WebhookRepository $webhookRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $webhook = $webhookRepository->find($id);
        if (!$webhook) {
            return new Response('Webhook non trouvé', Response::HTTP_NOT_FOUND);
        }

        $channel = $this->getChannelIfAdmin($webhook);
        if (!$channel) {
            return new Response('Accès refusé ou canal introuvable', Response::HTTP_FORBIDDEN);
        }

        $entityManager->remove($webhook);
        $entityManager->flush();

        return $this->renderWebhooksResponse($channel, $webhookRepository);
    }

    private function getChannelIfAdmin(Webhook $webhook): ?Channel
    {
        $channel = $webhook->getChannel();
        if (!$channel) {
            return null;
        }

        /** @var \App\Entity\User|null $currentUser */
        $currentUser = $this->getUser();
        if ($currentUser === null) {
            return null;
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$channel->isAdministrator($currentUser)) {
            return null;
        }

        return $channel;
    }

    private function renderWebhooksResponse(\App\Entity\Channel $channel, WebhookRepository $webhookRepository): Response
    {
        return $this->render('dashboard/_channel_webhooks.html.twig', [
            'activeChannel' => $channel,
            'webhooks' => $webhookRepository->findBy(['channel' => $channel], ['createdAt' => 'ASC']),
        ]);
    }
}
