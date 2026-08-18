<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\CsrfValidationSubscriber;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[AllowMockObjectsWithoutExpectations]
final class CsrfValidationSubscriberTest extends TestCase
{
    private CsrfTokenManagerInterface $tokenManager;
    private Security $security;

    protected function setUp(): void
    {
        $this->tokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $this->security = $this->createMock(Security::class);
    }

    private function createSubscriber(string $env = 'prod'): CsrfValidationSubscriber
    {
        return new CsrfValidationSubscriber($this->tokenManager, $this->security, $env);
    }

    private function createMainEvent(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function createSubRequestEvent(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST,
        );
    }

    #[Test]
    public function ignoresInTestEnvironment(): void
    {
        $subscriber = $this->createSubscriber('test');
        $request = Request::create('/channels/general/join', 'POST');
        $event = $this->createMainEvent($request);

        $this->tokenManager->expects($this->never())->method('isTokenValid');
        $subscriber->onKernelRequest($event);
    }

    #[Test]
    public function ignoresSubRequests(): void
    {
        $subscriber = $this->createSubscriber('prod');
        $request = Request::create('/channels/general/join', 'POST');
        $event = $this->createSubRequestEvent($request);

        $this->tokenManager->expects($this->never())->method('isTokenValid');
        $subscriber->onKernelRequest($event);
    }

    #[Test]
    public function ignoresGetRequests(): void
    {
        $subscriber = $this->createSubscriber('prod');
        $request = Request::create('/channels/general', 'GET');
        $event = $this->createMainEvent($request);

        $this->tokenManager->expects($this->never())->method('isTokenValid');
        $subscriber->onKernelRequest($event);
    }

    #[Test]
    public function ignoresUnauthenticatedUsers(): void
    {
        $subscriber = $this->createSubscriber('prod');
        $request = Request::create('/channels/general/join', 'POST');
        $event = $this->createMainEvent($request);

        $this->security->expects($this->once())->method('getUser')->willReturn(null);
        $this->tokenManager->expects($this->never())->method('isTokenValid');

        $subscriber->onKernelRequest($event);
    }

    #[Test]
    public function throwsWhenCsrfTokenMissingOnPost(): void
    {
        $subscriber = $this->createSubscriber('prod');
        $request = Request::create('/channels/general/join', 'POST');
        $event = $this->createMainEvent($request);

        $this->security->expects($this->once())->method('getUser')->willReturn(new User());

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('CSRF token is missing.');

        $subscriber->onKernelRequest($event);
    }

    #[Test]
    public function throwsWhenCsrfTokenInvalid(): void
    {
        $subscriber = $this->createSubscriber('prod');
        $request = Request::create('/channels/general/join', 'POST', ['_csrf_token' => 'invalid_token']);
        $event = $this->createMainEvent($request);

        $this->security->expects($this->once())->method('getUser')->willReturn(new User());
        $this->tokenManager
            ->expects($this->once())
            ->method('isTokenValid')
            ->with($this->equalTo(new CsrfToken('app', 'invalid_token')))
            ->willReturn(false);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Invalid CSRF token.');

        $subscriber->onKernelRequest($event);
    }

    #[Test]
    public function succeedsWithValidPostParameter(): void
    {
        $subscriber = $this->createSubscriber('prod');
        $request = Request::create('/channels/general/join', 'POST', ['_csrf_token' => 'valid_token']);
        $event = $this->createMainEvent($request);

        $this->security->expects($this->once())->method('getUser')->willReturn(new User());
        $this->tokenManager
            ->expects($this->once())
            ->method('isTokenValid')
            ->with($this->equalTo(new CsrfToken('app', 'valid_token')))
            ->willReturn(true);

        $subscriber->onKernelRequest($event);
    }

    #[Test]
    public function succeedsWithValidHeader(): void
    {
        $subscriber = $this->createSubscriber('prod');
        $request = Request::create('/channels/general/join', 'POST');
        $request->headers->set('X-CSRF-Token', 'valid_token_from_header');
        $event = $this->createMainEvent($request);

        $this->security->expects($this->once())->method('getUser')->willReturn(new User());
        $this->tokenManager
            ->expects($this->once())
            ->method('isTokenValid')
            ->with($this->equalTo(new CsrfToken('app', 'valid_token_from_header')))
            ->willReturn(true);

        $subscriber->onKernelRequest($event);
    }
}
