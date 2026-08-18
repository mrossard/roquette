<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SlashCommand;

use App\Entity\Channel;
use App\Entity\User;
use App\Message\LlmQueryMessage;
use App\Service\LlmRateLimiter;
use App\Service\SlashCommand\PollSlashCommand;
use App\Service\SlashCommand\RateLimitedOobRenderer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AllowMockObjectsWithoutExpectations]
class PollSlashCommandTest extends TestCase
{
    private MessageBusInterface $messageBus;
    private TranslatorInterface $translator;
    private Environment $twig;
    private LlmRateLimiter $llmRateLimiter;
    private RateLimitedOobRenderer $rateLimitedRenderer;
    private PollSlashCommand $command;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->twig = $this->createMock(Environment::class);
        $this->llmRateLimiter = $this->createMock(LlmRateLimiter::class);
        $this->rateLimitedRenderer = $this->createMock(RateLimitedOobRenderer::class);

        $this->translator->method('trans')->willReturnArgument(0);
        $this->twig->method('render')->willReturn('<div>rendered</div>');

        $this->command = new PollSlashCommand(
            $this->messageBus,
            $this->translator,
            $this->twig,
            $this->llmRateLimiter,
            $this->rateLimitedRenderer,
        );
    }

    #[Test]
    public function commandNameIsPoll(): void
    {
        $this->assertSame('poll', $this->command->getName());
        $this->assertNull($this->command->processPreview('What?'));
    }

    #[Test]
    public function emptyArgsRendersPrompt(): void
    {
        $channel = new Channel();
        $user = new User();

        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->command->execute('', $channel, $user);

        $this->assertNotNull($result->response);
        $this->assertSame(200, $result->response->getStatusCode());
    }

    #[Test]
    public function validPollRequestDispatchesLlmQuery(): void
    {
        $channel = new Channel();
        $channel->setSlug('general');
        $user = new User();
        $userRef = new \ReflectionProperty(User::class, 'id');
        $userRef->setValue($user, 1);

        $this->llmRateLimiter->method('consume')->willReturn(true);
        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(LlmQueryMessage::class))
            ->willReturn(new Envelope(new \stdClass()));

        $result = $this->command->execute('Team lunch between Pizza and Burger?', $channel, $user);

        $this->assertNotNull($result->response);
        $this->assertSame(200, $result->response->getStatusCode());
    }

    #[Test]
    public function rateLimitedPollRequestDoesNotDispatch(): void
    {
        $channel = new Channel();
        $channel->setSlug('general');
        $user = new User();
        $userRef = new \ReflectionProperty(User::class, 'id');
        $userRef->setValue($user, 1);

        $this->llmRateLimiter->method('consume')->willReturn(false);
        $this->messageBus->expects($this->never())->method('dispatch');
        $this->rateLimitedRenderer
            ->method('render')
            ->willReturn(new \Symfony\Component\HttpFoundation\Response('', 429));

        $result = $this->command->execute('Team lunch between Pizza and Burger?', $channel, $user);

        $this->assertNotNull($result->response);
        $this->assertSame(429, $result->response->getStatusCode());
    }
}
