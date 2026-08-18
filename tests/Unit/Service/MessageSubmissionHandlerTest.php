<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Service\ChannelAccessService;
use App\Service\ChannelManager;
use App\Service\MessagePublishService;
use App\Service\MessageSubmissionHandler;
use App\Service\PublishResult;
use App\Service\SlashCommandHandler;
use App\Service\SlashCommandResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AllowMockObjectsWithoutExpectations]
class MessageSubmissionHandlerTest extends TestCase
{
    private ChannelManager $channelManager;
    private ChannelAccessService $channelAccessService;
    private MessagePublishService $publishService;
    private SlashCommandHandler $slashCommandHandler;
    private RequestStack $requestStack;
    private Environment $twig;
    private TranslatorInterface $translator;
    private RateLimiterFactoryInterface $rateLimiter;
    private Session $session;
    private MessageSubmissionHandler $handler;

    protected function setUp(): void
    {
        $this->channelManager = $this->createMock(ChannelManager::class);
        $this->channelAccessService = $this->createMock(ChannelAccessService::class);
        $this->publishService = $this->createMock(MessagePublishService::class);
        $this->slashCommandHandler = $this->createMock(SlashCommandHandler::class);
        $this->requestStack = new RequestStack();
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->start();
        $this->twig = $this->createMock(Environment::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnArgument(0);
        $this->twig->method('render')->willReturn('<form></form>');
        $this->rateLimiter = $this->createMock(RateLimiterFactoryInterface::class);

        $workspaceRepository = $this->createMock(\App\Repository\WorkspaceRepository::class);
        $workspaceContext = new \App\Service\WorkspaceContext($this->requestStack, $workspaceRepository);

        $this->handler = new MessageSubmissionHandler(
            $this->channelManager,
            $this->channelAccessService,
            $this->publishService,
            $this->slashCommandHandler,
            $this->requestStack,
            $this->twig,
            $this->translator,
            $this->rateLimiter,
            $workspaceContext,
        );
    }

    private function createAcceptedLimiter(): LimiterInterface
    {
        $limit = $this->createMock(RateLimit::class);
        $limit->method('isAccepted')->willReturn(true);
        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->willReturn($limit);

        return $limiter;
    }

    private function createRejectedLimiter(): LimiterInterface
    {
        $limit = $this->createMock(RateLimit::class);
        $limit->method('isAccepted')->willReturn(false);
        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->willReturn($limit);

        return $limiter;
    }

    #[Test]
    public function channelNotFoundThrowsNotFoundHttpException(): void
    {
        $user = new User();
        $this->channelManager->method('findChannelBySlug')->willThrowException(new NotFoundHttpException());

        $request = new Request();
        $this->expectException(NotFoundHttpException::class);
        $this->handler->handle('unknown', $request, $user);
    }

    #[Test]
    public function accessDeniedThrowsAccessDeniedHttpException(): void
    {
        $user = new User();
        $channel = new Channel();
        $this->channelManager->method('findChannelBySlug')->willReturn($channel);
        $this->channelAccessService->method('canUserAccess')->willReturn(false);

        $request = new Request();
        $this->expectException(AccessDeniedHttpException::class);
        $this->handler->handle('general', $request, $user);
    }

    #[Test]
    public function rateLimitedReturns429(): void
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, 1);

        $channel = new Channel();
        $this->channelManager->method('findChannelBySlug')->willReturn($channel);
        $this->channelAccessService->method('canUserAccess')->willReturn(true);
        $this->rateLimiter->method('create')->willReturn($this->createRejectedLimiter());

        $request = new Request();
        $this->requestStack->push($request);
        $request->setSession($this->session);

        $response = $this->handler->handle('general', $request, $user);
        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    }

    #[Test]
    public function emptyMessageReturns200Form(): void
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, 1);

        $channel = new Channel();
        $this->channelManager->method('findChannelBySlug')->willReturn($channel);
        $this->channelAccessService->method('canUserAccess')->willReturn(true);
        $this->rateLimiter->method('create')->willReturn($this->createAcceptedLimiter());

        $request = new Request([], ['message' => '   ']);
        $this->requestStack->push($request);
        $request->setSession($this->session);

        $response = $this->handler->handle('general', $request, $user);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<form></form>', $response->getContent());
    }

    #[Test]
    public function slashCommandReturningResponseIsReturnedDirectly(): void
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, 1);

        $channel = new Channel();
        $this->channelManager->method('findChannelBySlug')->willReturn($channel);
        $this->channelAccessService->method('canUserAccess')->willReturn(true);
        $this->rateLimiter->method('create')->willReturn($this->createAcceptedLimiter());

        $directResponse = new Response('modal content');
        $this->slashCommandHandler->method('process')->willReturn(new SlashCommandResult('/shrug', $directResponse));

        $request = new Request([], ['message' => '/shrug']);
        $this->requestStack->push($request);
        $request->setSession($this->session);

        $response = $this->handler->handle('general', $request, $user);
        $this->assertSame($directResponse, $response);
    }

    #[Test]
    public function successfulPublishReturns200Form(): void
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, 1);

        $channel = new Channel();
        $this->channelManager->method('findChannelBySlug')->willReturn($channel);
        $this->channelAccessService->method('canUserAccess')->willReturn(true);
        $this->rateLimiter->method('create')->willReturn($this->createAcceptedLimiter());

        $message = new Message();
        $this->publishService->method('publish')->willReturn(PublishResult::ok($channel, $message, '<div>item</div>'));

        $request = new Request([], ['message' => 'Hello world']);
        $this->requestStack->push($request);
        $request->setSession($this->session);

        $response = $this->handler->handle('general', $request, $user);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<form></form>', $response->getContent());
    }

    #[Test]
    public function publishServiceErrorReturnsErrorResponse(): void
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, 1);

        $channel = new Channel();
        $this->channelManager->method('findChannelBySlug')->willReturn($channel);
        $this->channelAccessService->method('canUserAccess')->willReturn(true);
        $this->rateLimiter->method('create')->willReturn($this->createAcceptedLimiter());

        $this->publishService->method('publish')->willReturn(PublishResult::error('Bad request', $channel, 422));

        $request = new Request([], ['message' => 'Hello']);
        $this->requestStack->push($request);
        $request->setSession($this->session);

        $response = $this->handler->handle('general', $request, $user);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('<form></form>', $response->getContent());
        $this->assertTrue($this->session->getFlashBag()->has('error'));
        $this->assertSame(['Bad request'], $this->session->getFlashBag()->peek('error'));
    }

    #[Test]
    public function publishWithOobHtmlAppendsToFormResponse(): void
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, 1);

        $channel = new Channel();
        $this->channelManager->method('findChannelBySlug')->willReturn($channel);
        $this->channelAccessService->method('canUserAccess')->willReturn(true);
        $this->rateLimiter->method('create')->willReturn($this->createAcceptedLimiter());

        $message = new Message();
        $oob = '<div hx-swap-oob="beforeend:#live-feed"><div id="help-123">Loading...</div></div>';
        $this->publishService->method('publish')->willReturn(PublishResult::ok($channel, $message, $oob));

        $request = new Request([], ['message' => '@robot aide']);
        $this->requestStack->push($request);
        $request->setSession($this->session);

        $response = $this->handler->handle('general', $request, $user);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame("<form></form>\n" . $oob, $response->getContent());
    }
}
