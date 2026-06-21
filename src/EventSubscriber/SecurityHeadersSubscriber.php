<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

readonly class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(env: 'MERCURE_PUBLIC_URL')]
        private string $mercurePublicUrl,
        #[Autowire(env: 'EMOJI_BASE_URL')]
        private string $emojiBaseUrl,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();

        // 1. Content Security Policy (CSP)
        $mercureHost = '';
        if ($this->mercurePublicUrl !== '') {
            $parsed = parse_url($this->mercurePublicUrl);
            if (isset($parsed['host'])) {
                $scheme = $parsed['scheme'] ?? 'https';
                $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
                $mercureHost = $scheme . '://' . $parsed['host'] . $port;
            }
        }

        $emojiHost = '';
        if ($this->emojiBaseUrl !== '') {
            $parsed = parse_url($this->emojiBaseUrl);
            if (isset($parsed['host'])) {
                $scheme = $parsed['scheme'] ?? 'https';
                $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
                $emojiHost = $scheme . '://' . $parsed['host'] . $port;
            }
        }

        $connectSrc = "'self' " . $mercureHost;
        $imgSrc = "'self' data: " . $emojiHost . ' https://*'; // Allow remote custom user avatars via https
        $styleSrc = "'self' 'unsafe-inline' https://fonts.googleapis.com";
        $fontSrc = "'self' https://fonts.gstatic.com";
        $scriptSrc = "'self' 'unsafe-inline' 'unsafe-eval' data:"; // Keep unsafe-inline for inline translations and theme scripts; unsafe-eval for HTMX hx-on:* handlers

        $csp = sprintf(
            "default-src 'self'; script-src %s; worker-src 'self' data:; style-src %s; img-src %s; font-src %s; connect-src %s; media-src 'self' data:; frame-ancestors 'none'; object-src 'none';",
            $scriptSrc,
            $styleSrc,
            $imgSrc,
            $fontSrc,
            $connectSrc,
        );

        $response->headers->set('Content-Security-Policy', $csp);

        // 2. Additional standard security headers
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=()');
    }
}
