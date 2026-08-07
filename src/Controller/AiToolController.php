<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\ToolActionSigner;
use App\Ai\ToolRegistry;
use App\Entity\User;
use App\Service\MessageFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\AI\Platform\Result\ToolCall;
use Twig\Environment;

/**
 * Handles user confirmation of side-effect tool actions requested by the AI assistant.
 */
final class AiToolController extends AbstractController
{
    public function __construct(
        private readonly ToolActionSigner $toolActionSigner,
        private readonly ToolRegistry $toolRegistry,
        private readonly HubInterface $hub,
        private readonly Environment $twig,
        private readonly MessageFormatter $messageFormatter,
    ) {}

    #[Route('/ai/tool/confirm', name: 'ai_tool_confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        string $mercureTopicPrefix,
        RateLimiterFactoryInterface $llmApiLimiter,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        $token = (string) $request->request->get('token', '');
        $payload = $this->toolActionSigner->verify($token, $user->getId());
        if ($payload === null) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $limiter = $llmApiLimiter->create('tool_confirm_' . $user->getId());
        if (!$limiter->consume(1)->isAccepted()) {
            return new Response('', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $toolName = $payload['tool'] ?? null;
        $arguments = $payload['args'] ?? [];
        if (!\is_string($toolName) || !\is_array($arguments)) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $result = $this->toolRegistry->execute(
            new ToolCall('confirm-' . uniqid(), $toolName, $arguments),
            $payload['uid'] ?? null,
            $payload['ws'] ?? null,
        );

        $topic = $mercureTopicPrefix . '/users/' . $user->getUsername();
        $renderedHtml = $this->twig->render('dashboard/_help_message_update.html.twig', [
            'helpMessageId' => (string) ($payload['helpMessageId'] ?? ''),
            'html' => $this->messageFormatter->format($result),
            'timestamp' => new \DateTime(),
            'channelSlug' => (string) ($payload['channelSlug'] ?? ''),
        ]);

        $this->hub->publish(new Update($topic, $renderedHtml, true, null, 'help_stream_update'));

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
