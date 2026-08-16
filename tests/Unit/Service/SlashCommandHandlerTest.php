<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\User;
use App\Service\SlashCommandHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AllowMockObjectsWithoutExpectations]
class SlashCommandHandlerTest extends TestCase
{
    private SlashCommandHandler $handler;

    protected function setUp(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $twig = $this->createMock(Environment::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $limiter = $this->createMock(RateLimiterFactoryInterface::class);

        $this->handler = new SlashCommandHandler($bus, $translator, $twig, $em, $limiter);
    }

    #[Test]
    public function processTransformsShrug(): void
    {
        $channel = new Channel();
        $user = new User();

        $result = $this->handler->process('/shrug quoi de neuf', $channel, $user);

        $this->assertNull($result->response);
        $this->assertSame('quoi de neuf ¯\_(ツ)_/¯', $result->messageText);
    }

    #[Test]
    public function processTransformsMe(): void
    {
        $channel = new Channel();
        $user = new User();

        $result = $this->handler->process('/me saute de joie', $channel, $user);

        $this->assertNull($result->response);
        $this->assertSame('/me saute de joie', $result->messageText);
    }

    #[Test]
    public function processReturnsUnhandledForUnknownCommands(): void
    {
        $channel = new Channel();
        $user = new User();

        $result = $this->handler->process('/unknown foo bar', $channel, $user);

        $this->assertNull($result->response);
        $this->assertSame('/unknown foo bar', $result->messageText);
    }
}
