<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Ai\PendingConfirmationService;
use App\Entity\Channel;
use App\Entity\User;
use App\Message\LlmQueryMessage;
use App\Service\LlmRateLimiter;
use App\Service\RobotInteractionService;
use App\Service\RobotUserProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class RobotInteractionServiceTest extends TestCase
{
    #[Test]
    public function isRobotMentionedDetectsValidMentions(): void
    {
        $robotUser = new User();
        $robotUser->setUsername('robot-roquette');

        $robotUserProvider = $this->createStub(RobotUserProvider::class);
        $robotUserProvider->method('getRobotUser')->willReturn($robotUser);

        $llmRateLimiter = $this->createStub(LlmRateLimiter::class);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $twig = $this->createStub(Environment::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $pendingConfirmationService = $this->createStub(PendingConfirmationService::class);

        $service = new RobotInteractionService(
            $robotUserProvider,
            $llmRateLimiter,
            $messageBus,
            $twig,
            $translator,
            $pendingConfirmationService,
        );

        $this->assertTrue($service->isRobotMentioned('Salut @robot-roquette tu peux m\'aider ?'));
        $this->assertTrue($service->isRobotMentioned('@robot quel temps fait-il ?'));
        $this->assertFalse($service->isRobotMentioned('Salut @alice et @bob'));
        $this->assertFalse($service->isRobotMentioned('Je parle de robot sans arobase'));
    }

    #[Test]
    public function checkRobotDmLlmRateLimitReturnsErrorWhenExceeded(): void
    {
        $user = new User();
        $channel = new Channel();
        $channel->setSlug('dm-robot-user');

        $robotUserProvider = $this->createStub(RobotUserProvider::class);
        $robotUserProvider->method('getDmChannelSlug')->willReturn('dm-robot-user');

        $llmRateLimiter = $this->createMock(LlmRateLimiter::class);
        $llmRateLimiter->expects($this->once())->method('consume')->willReturn(false);

        $messageBus = $this->createStub(MessageBusInterface::class);
        $twig = $this->createStub(Environment::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Trop de requêtes');
        $pendingConfirmationService = $this->createStub(PendingConfirmationService::class);

        $service = new RobotInteractionService(
            $robotUserProvider,
            $llmRateLimiter,
            $messageBus,
            $twig,
            $translator,
            $pendingConfirmationService,
        );

        $result = $service->checkRobotDmLlmRateLimit($user, $channel);

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $result->statusCode);
    }

    #[Test]
    public function handleRobotMentionInChannelDispatchesLlmQueryAndRendersOob(): void
    {
        $robotUserProvider = $this->createStub(RobotUserProvider::class);
        $llmRateLimiter = $this->createStub(LlmRateLimiter::class);
        $llmRateLimiter->method('consume')->willReturn(true);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(LlmQueryMessage::class))
            ->willReturn(new Envelope(new \stdClass()));

        $twig = $this->createMock(Environment::class);
        $twig
            ->expects($this->once())
            ->method('render')
            ->with('dashboard/_help_message_oob.html.twig', $this->callback(is_array(...)))
            ->willReturn('<div>OOB help message</div>');

        $translator = $this->createStub(TranslatorInterface::class);
        $pendingConfirmationService = $this->createStub(PendingConfirmationService::class);

        $service = new RobotInteractionService(
            $robotUserProvider,
            $llmRateLimiter,
            $messageBus,
            $twig,
            $translator,
            $pendingConfirmationService,
        );

        $channel = new Channel();
        $channel->setSlug('general');
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, 1);

        $result = $service->handleRobotMentionInChannel($channel, $user, '@robot aide moi');

        $this->assertTrue($result->success);
        $this->assertSame('<div>OOB help message</div>', $result->renderedHtml);
    }

    #[Test]
    public function tryHandleConfirmationExecutesPendingConfirmation(): void
    {
        $robotUserProvider = $this->createStub(RobotUserProvider::class);
        $llmRateLimiter = $this->createStub(LlmRateLimiter::class);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $twig = $this->createStub(Environment::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $pendingConfirmationService = $this->createMock(PendingConfirmationService::class);
        $pendingConfirmationService->expects($this->once())->method('getPendingConfirmation')->willReturn('token-123');
        $pendingConfirmationService->expects($this->once())->method('isConfirmation')->willReturn(true);
        $pendingConfirmationService->expects($this->once())->method('executeConfirmation')->willReturn(true);

        $service = new RobotInteractionService(
            $robotUserProvider,
            $llmRateLimiter,
            $messageBus,
            $twig,
            $translator,
            $pendingConfirmationService,
        );

        $channel = new Channel();
        $channel->setSlug('general');
        $user = new User();

        $result = $service->tryHandleConfirmation($user, $channel, 'ok');

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
    }
}
