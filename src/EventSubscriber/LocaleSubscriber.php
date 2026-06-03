<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use Symfony\Component\Translation\LocaleSwitcher;

class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private EntityManagerInterface $entityManager,
        private LocaleSwitcher $localeSwitcher,
        #[Autowire('%kernel.default_locale%')]
        private string $defaultLocale = 'en',
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // Priority 5 runs after the Firewall (which is priority 8),
            // so we can access the authenticated user.
            KernelEvents::REQUEST => [['onKernelRequest', 5]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->hasPreviousSession()) {
            return;
        }

        // 1. Check if _locale query parameter is explicitly provided
        $queryLocale = $request->query->get('_locale');
        if ($queryLocale && in_array($queryLocale, ['fr', 'en'], true)) {
            $request->getSession()->set('_locale', $queryLocale);
            $request->setLocale($queryLocale);
            $this->localeSwitcher->setLocale($queryLocale);

            // If user is logged in, also update their database locale preference
            $token = $this->tokenStorage->getToken();
            if ($token && ($user = $token->getUser()) instanceof \App\Entity\User) {
                if ($user->getLocale() !== $queryLocale) {
                    $user->setLocale($queryLocale);
                    $this->entityManager->flush();
                }
            }

            return;
        }

        // 2. Check if user is authenticated and has a locale preference saved
        $token = $this->tokenStorage->getToken();
        if ($token && ($user = $token->getUser()) instanceof \App\Entity\User) {
            $userLocale = $user->getLocale();
            $request->setLocale($userLocale);
            $request->getSession()->set('_locale', $userLocale);
            $this->localeSwitcher->setLocale($userLocale);

            return;
        }

        // 3. Fallback to session locale, or default locale
        $sessionLocale = $request->getSession()->get('_locale', $this->defaultLocale);
        $request->setLocale($sessionLocale);
        $this->localeSwitcher->setLocale($sessionLocale);
    }
}
