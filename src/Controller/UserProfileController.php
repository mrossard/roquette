<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class UserProfileController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/user/update-color', name: 'app_user_update_color', methods: ['POST'])]
    public function updateColor(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $hue = $request->request->get('hue');
        if ($hue === null) {
            return new Response($this->translator->trans('Teinte manquante.'), 400);
        }

        $hueVal = (int) $hue;
        if ($hueVal < 0 || $hueVal > 360) {
            return new Response($this->translator->trans('Teinte invalide.'), 400);
        }

        $currentUser->setCustomHue($hueVal);
        $entityManager->flush();

        return new Response(null, 204, ['HX-Refresh' => 'true']);
    }

    #[Route('/user/update-theme', name: 'app_user_update_theme', methods: ['POST'])]
    public function updateTheme(EntityManagerInterface $entityManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $newTheme = $currentUser->getTheme() === 'dark' ? 'light' : 'dark';
        $currentUser->setTheme($newTheme);
        $entityManager->flush();

        return new Response(null, 204, ['HX-Refresh' => 'true']);
    }

    #[Route('/user/update-status', name: 'app_user_update_status', methods: ['POST'])]
    public function updateStatus(
        Request $request,
        EntityManagerInterface $entityManager,
        MercurePublisher $mercurePublisher,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $status = $request->request->get('status');
        if (in_array($status, ['auto', 'online', 'away', 'busy', 'offline'], true)) {
            $currentUser->setStatusOverride($status === 'auto' ? null : $status);
            $entityManager->flush();

            $mercurePublisher->publishToTopic(
                $mercurePublisher->getStatusTopic(),
                [
                    'type' => 'user_status_changed',
                    'username' => $currentUser->getUsername(),
                    'status' => $currentUser->getStatus(),
                    'statusLabel' => $currentUser->getStatusLabel(),
                    'statusOverride' => $currentUser->getStatusOverride() ?? 'auto',
                    'lastActive' => $currentUser->getLastActiveAt()?->getTimestamp(),
                ],
                true,
                'user_status_changed',
            );

            return new Response(null, 204);
        }

        return new Response($this->translator->trans('Statut invalide.'), 400);
    }

    #[Route('/user/ping', name: 'app_user_ping', methods: ['GET', 'POST'])]
    public function ping(): Response
    {
        return new Response(null, 204);
    }

    #[Route('/csrf-token', name: 'app_csrf_token', methods: ['GET'])]
    public function csrfToken(CsrfTokenManagerInterface $csrfTokenManager): JsonResponse
    {
        return new JsonResponse([
            'token' => $csrfTokenManager->getToken('app')->getValue(),
        ]);
    }
}
