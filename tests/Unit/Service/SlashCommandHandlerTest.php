<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\User;
use App\Service\SlashCommand\MeSlashCommand;
use App\Service\SlashCommand\ShrugSlashCommand;
use App\Service\SlashCommandHandler;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SlashCommandHandlerTest extends TestCase
{
    private SlashCommandHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new SlashCommandHandler([
            new ShrugSlashCommand(),
            new MeSlashCommand(),
        ]);
    }

    #[Test]
    public function processPreviewTransformsShrugAndMe(): void
    {
        $this->assertSame('hello ¯\_(ツ)_/¯', $this->handler->processPreview('/shrug hello'));
        $this->assertSame('¯\_(ツ)_/¯', $this->handler->processPreview('/shrug'));
        $this->assertSame('*hello*', $this->handler->processPreview('/me hello'));
        $this->assertSame('', $this->handler->processPreview('/me'));
        $this->assertSame('ordinary text', $this->handler->processPreview('ordinary text'));
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
