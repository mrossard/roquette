<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SlashCommand;

use App\Entity\Channel;
use App\Entity\User;
use App\Service\SlashCommand\ShrugSlashCommand;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ShrugSlashCommandTest extends TestCase
{
    private ShrugSlashCommand $command;

    protected function setUp(): void
    {
        $this->command = new ShrugSlashCommand();
    }

    #[Test]
    public function commandNameIsShrug(): void
    {
        $this->assertSame('shrug', $this->command->getName());
    }

    #[Test]
    public function processPreview(): void
    {
        $this->assertSame('¯\_(ツ)_/¯', $this->command->processPreview(''));
        $this->assertSame('hello ¯\_(ツ)_/¯', $this->command->processPreview('hello'));
    }

    #[Test]
    public function executeTransformsText(): void
    {
        $channel = new Channel();
        $user = new User();

        $result = $this->command->execute('foo', $channel, $user);
        $this->assertNull($result->response);
        $this->assertSame('foo ¯\_(ツ)_/¯', $result->messageText);
    }
}
