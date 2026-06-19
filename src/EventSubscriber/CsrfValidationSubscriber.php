<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class CsrfValidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CsrfTokenManagerInterface $csrfTokenManager,
        private Security $security,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.environment%')]
        private string $environment,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($this->environment === 'test') {
            return;
        }

        $request = $event->getRequest();

        // Seules les requêtes d'écriture (POST, PUT, DELETE, PATCH)
        if (!in_array($request->getMethod(), ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            return;
        }

        // Si l'utilisateur n'est pas connecté, on ne valide pas via ce token CSRF global 'app'.
        // (les requêtes de login/déconnexion, OAuth, ou webhooks gèrent leur propre sécurité).
        if (!$this->security->getUser()) {
            return;
        }

        // Exclure les routes spécifiques si nécessaire
        $route = $request->attributes->get('_route');
        if (in_array($route, ['app_login', 'app_logout'], true)) {
            return;
        }

        // Récupérer le token CSRF
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->get('_csrf_token');

        if (!$token || !is_string($token)) {
            throw new AccessDeniedHttpException('CSRF token is missing.');
        }

        $csrfToken = new CsrfToken('app', $token);
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
