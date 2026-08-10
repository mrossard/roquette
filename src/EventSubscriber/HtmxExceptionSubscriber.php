<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class HtmxExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 10],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->headers->has('HX-Request')) {
            return;
        }

        $throwable = $event->getThrowable();
        if (!$throwable instanceof HttpExceptionInterface) {
            return;
        }

        $response = new Response($throwable->getMessage(), $throwable->getStatusCode(), $throwable->getHeaders());
        $event->setResponse($response);
    }
}
