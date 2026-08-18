<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\PendingConfirmationService;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles user confirmation of side-effect tool actions requested by the AI assistant.
 */
final class AiToolController extends AbstractController
{
    public function __construct(
        private readonly PendingConfirmationService $pendingConfirmationService,
    ) {}

    #[Route('/ai/tool/confirm', name: 'ai_tool_confirm', methods: ['POST'])]
    public function confirm(Request $request): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        $token = (string) $request->request->get('token', '');
        $success = $this->pendingConfirmationService->executeConfirmation($token, $user);

        if (!$success) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
