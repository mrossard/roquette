<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Service\ChannelAccessService;
use App\Service\MessagePublisher;
use App\Service\MessagePublishService;
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
class MessagePublisherTest extends TestCase
{
    private ChannelRepository $channelRepository;
    private ChannelAccessService $channelAccessService;
    private MessagePublishService $publishService;
    private SlashCommandHandler $slashCommandHandler;
    private RequestStack $requestStack;
    private Environment $twig;
    private TranslatorInterface $translator;
    private RateLimiterFactoryInterface $rateLimiter;
    private Session $session;
    private MessagePublisher $publisher;

    protected function setUp(): void
    {
        $this->channelRepository = $this->createMock(ChannelRepository::class);
        $this->channelAccessService = $this->createMock(ChannelAccessService::class);
        $this->publishService = $this->createMock(MessagePublishService::class);
        $this->slashCommandHandler = $this->createMock(SlashCommandHandler::class);
        $this->requestStack = new RequestStack();
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->start();
        $this->twig = $this->createMock(Environment::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->rateLimiter = $this->createMock(RateLimiterFactoryInterface::class);

        $this->translator->method('trans')->willReturnArgument(0);
        $this->twig->method('render')->willReturn('<form></form>');

        $this->publisher = new MessagePublisher(
            $this->channelRepository,
            $this->channelAccessService,
            $this->publishService,
            $this->slashCommandHandler,
            $this->requestStack,
            $this->twig,
            $this->translator,
            $this->rateLimiter,
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
        $this->channelRepository->method('findOneBy')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->publisher->publish('unknown', new Request(), $user);
    }

    #[Test]
    public function channelAccessDeniedThrowsAccessDeniedHttpException(): void
    {
        $user = new User();
        $channel = new Channel();
        $this->channelRepository->method('findOneBy')->willReturn($channel);
        $this->channelAccessService->expects($this->once())->method('canUserAccess')->with($channel, $user)->willReturn(false);

        $this->expectException(AccessDeniedHttpException::class);
        $this->publisher->publish('general', new Request(), $user);
    }

    #[Test]
    public function rateLimitExceededReturns429(): void
    {
        $user = new User();
        $channel = new Channel();
        $this->channelRepository->method('findOneBy')->willReturn($channel);
        $this->channelAccessService->method('canUserAccess')->willReturn(true);
        $this->rateLimiter->method('create')->willReturn($this->createRejectedLimiter());

        $request = new Request();
        $request->setSession($this->session);
        $this->requestStack->push($request);

        $response = $this->publisher->publish('general', $request, $user);

        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        $this->assertNotEmpty($this->session->getFlashBag()->get('error'));
    }

    #[Test]
    public function emptyMessageReturnsFormWithoutCallingPublishService(): void
    {
        $user = new User();
        $channel = new Channel();
        $this->channelRepository->method('findOneBy')->willReturn($channel);
        $this->channelAccessService->method('canUserAccess')->willReturn(true);
        $this->rateLimiter->method('create')->willReturn($this->createAcceptedLimiter());

        $request = new Request([], ['message' => '   ']);
        $request->setSession($this->session);
        $this->requestStack->push($request);

        $this->publishService->expects($this->never())->method('publish');

        $response = $this->publisher->publish('general', $request, $user);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function slashCommandReturningResponseDirectlyReturnsIt(): void
    {
        $user = new User();
        $channel = new Channel();
        $this->channelRepository->method('findOneBy')->willReturn($channel);
        $this->channelAccessService->method('canUserAccess')->willReturn(true);
        $this->rateLimiter->method('create')->willReturn($this->createAcceptedLimiter());

        $directResponse = new Response('Direct Help Response', 200);
        $this->slashCommandHandler->method('process')->willReturn(
            SlashCommandResult::handled($directResponse),
        );

        $request = new Request([], ['message' => '/help']);
        $request->setSession($this->session);
        $this->requestStack->push($request);

        $response = $this->publisher->publish('general', $request, $user);
        $this->assertSame($directResponse, $response);
    }

    #[Test]
    public function successfulPublishReturnsForm(): void
    {
        $user = new User();
        $channel = new Channel();
        $this->channelRepository->method('findOneBy')->willReturn($channel);
        $this->channelAccessService->method('canUserAccess')->willReturn(true);
        $this->rateLimiter->method('create')->willReturn($this->createAcceptedLimiter());

        $this->publishService->method('publish')->willReturn(
            PublishResult::ok($channel, new Message(), '<div>message</div>'),
        );

        $request = new Request([], ['message' => 'Hello team']);
        $request->setSession($this->session);
        $this->requestStack->push($request);

        $response = $this->publisher->publish('general', $request, $user);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function failedPublishWithCustomStatusCodeRendersError(): void
    {
        $user = new User();
        $channel = new Channel();
        $this->channelRepository->method('findOneBy')->willReturn($channel);
        $this->channelAccessService->method('canUserAccess')->willReturn(true);
        $this->rateLimiter->method('create')->willReturn($this->createAcceptedLimiter());

        $this->publishService->method('publish')->willReturn(
            PublishResult::error('Poll needs 2 options', $channel, 400),
        );

        $request = new Request([], ['message' => 'my poll', 'poll_question' => 'Choice?']);
        $request->setSession($this->session);
        $this->requestStack->push($request);

        $response = $this->publisher->publish('general', $request, $user);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Poll needs 2 options', $response->getContent());
    }
}
