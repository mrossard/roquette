<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\HtmxExceptionSubscriber;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[AllowMockObjectsWithoutExpectations]
class HtmxExceptionSubscriberTest extends TestCase
{
    public function testHandlesHtmxHttpException(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $request->headers->set('HX-Request', 'true');

        $exception = new AccessDeniedHttpException('Accès refusé.');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $subscriber = new HtmxExceptionSubscriber();
        $subscriber->onKernelException($event);

        static::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        static::assertSame(403, $response->getStatusCode());
        static::assertSame('Accès refusé.', $response->getContent());
    }

    public function testIgnoresNonHtmxRequest(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();

        $exception = new NotFoundHttpException('Introuvable.');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $subscriber = new HtmxExceptionSubscriber();
        $subscriber->onKernelException($event);

        static::assertFalse($event->hasResponse());
    }
}
