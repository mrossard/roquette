<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class SecuritySubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(env: 'bool:AUTH_FORM_ENABLED')] private bool $authFormEnabled
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckPassportEvent::class => 'onCheckPassport',
        ];
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $authenticator = $event->getAuthenticator();
        if ($authenticator instanceof FormLoginAuthenticator && !$this->authFormEnabled) {
            throw new CustomUserMessageAuthenticationException('L\'authentification par mot de passe est désactivée.');
        }
    }
}
