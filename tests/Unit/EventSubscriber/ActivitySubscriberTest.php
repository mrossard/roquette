<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\ActivitySubscriber;
use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[AllowMockObjectsWithoutExpectations]
final class ActivitySubscriberTest extends TestCase
{
    private Security $security;
    private EntityManagerInterface $entityManager;
    private MercurePublisher $mercurePublisher;
    private ActivitySubscriber $subscriber;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->mercurePublisher = $this->createMock(MercurePublisher::class);

        $this->subscriber = new ActivitySubscriber($this->security, $this->entityManager, $this->mercurePublisher);
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
    public function ignoresSubRequests(): void
    {
        $request = Request::create('/');
        $event = $this->createSubRequestEvent($request);

        $this->security->expects($this->never())->method('getUser');
        $this->subscriber->onKernelRequest($event);
    }

    #[Test]
    public function ignoresPingEndpoint(): void
    {
        $request = Request::create('/user/ping', 'GET');
        $event = $this->createMainEvent($request);

        $this->security->expects($this->never())->method('getUser');
        $this->subscriber->onKernelRequest($event);
    }

    #[Test]
    public function ignoresUnauthenticatedUser(): void
    {
        $request = Request::create('/dashboard');
        $event = $this->createMainEvent($request);

        $this->security->expects($this->once())->method('getUser')->willReturn(null);
        $this->entityManager->expects($this->never())->method('flush');
        $this->mercurePublisher->expects($this->never())->method('publishUserStatus');

        $this->subscriber->onKernelRequest($event);
    }

    #[Test]
    public function updatesActivityAndPublishesStatusWhenStale(): void
    {
        $user = new User();
        $user->setUsername('alice');
        $user->setLastActiveAt(new \DateTimeImmutable('-2 minutes'));

        $request = Request::create('/dashboard');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $event = $this->createMainEvent($request);

        $this->security->expects($this->once())->method('getUser')->willReturn($user);
        $this->entityManager->expects($this->once())->method('flush');
        $this->mercurePublisher->expects($this->once())->method('publishUserStatus')->with($user);

        $this->subscriber->onKernelRequest($event);

        $this->assertNotNull($session->get('last_active_write'));
    }

    #[Test]
    public function skipsUpdateWhenThrottledBySession(): void
    {
        $user = new User();
        $user->setUsername('alice');
        $user->setLastActiveAt(new \DateTimeImmutable('-2 minutes'));

        $request = Request::create('/dashboard');
        $session = new Session(new MockArraySessionStorage());
        $session->set('last_active_write', time() - 10); // Written 10 seconds ago (<60s)
        $request->setSession($session);
        $event = $this->createMainEvent($request);

        $this->security->expects($this->once())->method('getUser')->willReturn($user);
        $this->entityManager->expects($this->never())->method('flush');
        $this->mercurePublisher->expects($this->never())->method('publishUserStatus');

        $this->subscriber->onKernelRequest($event);
    }
}
