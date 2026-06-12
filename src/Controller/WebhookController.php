<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\MessageRendererTrait;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Webhook;
use App\Repository\ChannelRepository;
use App\Repository\UserRepository;
use App\Repository\WebhookRepository;
use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class WebhookController extends AbstractController
{
    use MessageRendererTrait;

    #[Route('/api/webhooks/incoming/{token}', name: 'app_webhook_incoming', methods: ['POST'])]
    public function incoming(
        #[\SensitiveParameter]
        string $token,
        Request $request,
        WebhookRepository $webhookRepository,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        MercurePublisher $mercurePublisher,
    ): Response {
        $webhook = $webhookRepository->findOneBy(['token' => $token]);

        if (!$webhook) {
            return new JsonResponse(['error' => 'Webhook not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$webhook->isActive()) {
            return new JsonResponse(['error' => 'Webhook is inactive'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $content = $data['text'] ?? $data['content'] ?? null;
        if (null === $content || trim((string) $content) === '') {
            return new JsonResponse([
                'error' => 'Missing message content ("text" or "content")',
            ], Response::HTTP_BAD_REQUEST);
        }

        $customName = $data['username'] ?? $data['customAuthorName'] ?? $webhook->getName();
        $customAvatar = $data['avatar_url'] ?? $data['customAuthorAvatar'] ?? null;

        $robotUser = $userRepository->findOneBy(['username' => User::ROBOT_USERNAME]);
        if (!$robotUser) {
            $robotUser = $webhook->getCreator();
        }

        $message = new Message();
        $message->setChannel($webhook->getChannel());
        $message->setAuthor($robotUser);
        $message->setContent(trim((string) $content));
        $message->setCustomAuthorName((string) $customName);
        if ($customAvatar !== null) {
            $message->setCustomAuthorAvatar((string) $customAvatar);
        }

        $entityManager->persist($message);
        $entityManager->flush();

        $renderedHtml = $this->renderFeedItem($message);

        $mercurePublisher->publishNewMessage(
            $webhook->getChannel(),
            $message,
            $robotUser,
            (string) $content,
            $renderedHtml,
            $entityManager,
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

        return $this->render('dashboard/_channel_webhooks.html.twig', [
            'activeChannel' => $channel,
            'webhooks' => $webhookRepository->findBy(['channel' => $channel], ['createdAt' => 'ASC']),
        ]);
    }

    #[Route('/webhooks/{id}/toggle', name: 'app_webhook_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function toggle(
        int $id,
        WebhookRepository $webhookRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $webhook = $webhookRepository->find($id);

        if (!$webhook) {
            return new Response('Webhook non trouvé', Response::HTTP_NOT_FOUND);
        }

        $channel = $webhook->getChannel();
        if (!$channel) {
            return new Response('Canal associé non trouvé', Response::HTTP_NOT_FOUND);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$channel->isAdministrator($currentUser)) {
            return new Response('Accès refusé', Response::HTTP_FORBIDDEN);
        }

        $webhook->setIsActive(!$webhook->isActive());
        $entityManager->flush();

        return $this->render('dashboard/_channel_webhooks.html.twig', [
            'activeChannel' => $channel,
            'webhooks' => $webhookRepository->findBy(['channel' => $channel], ['createdAt' => 'ASC']),
        ]);
    }

    #[Route('/webhooks/{id}/delete', name: 'app_webhook_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(
        int $id,
        WebhookRepository $webhookRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $webhook = $webhookRepository->find($id);

        if (!$webhook) {
            return new Response('Webhook non trouvé', Response::HTTP_NOT_FOUND);
        }

        $channel = $webhook->getChannel();
        if (!$channel) {
            return new Response('Canal associé non trouvé', Response::HTTP_NOT_FOUND);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$channel->isAdministrator($currentUser)) {
            return new Response('Accès refusé', Response::HTTP_FORBIDDEN);
        }

        $entityManager->remove($webhook);
        $entityManager->flush();

        return $this->render('dashboard/_channel_webhooks.html.twig', [
            'activeChannel' => $channel,
            'webhooks' => $webhookRepository->findBy(['channel' => $channel], ['createdAt' => 'ASC']),
        ]);
    }
}
