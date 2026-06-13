<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;


use App\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[AllowMockObjectsWithoutExpectations]
class SecurityHeadersSubscriberTest extends TestCase
{
    public function testOnKernelResponseSetsHeaders(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $response = new Response();
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $subscriber = new SecurityHeadersSubscriber(
            'http://localhost:3000/.well-known/mercure',
            'http://localhost:8080/emojis'
        );

        $subscriber->onKernelResponse($event);

        $headers = $response->headers;
        static::assertTrue($headers->has('Content-Security-Policy'));
        static::assertSame('DENY', $headers->get('X-Frame-Options'));
        static::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        static::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));

        $csp = $headers->get('Content-Security-Policy');
        static::assertStringContainsString('http://localhost:3000', $csp);
        static::assertStringContainsString('http://localhost:8080', $csp);
    }
}
