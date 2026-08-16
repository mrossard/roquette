<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SlashCommand;

use App\Entity\Channel;
use App\Entity\User;
use App\Service\SlashCommand\MeSlashCommand;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class MeSlashCommandTest extends TestCase
{
    private MeSlashCommand $command;

    protected function setUp(): void
    {
        $this->command = new MeSlashCommand();
    }

    #[Test]
    public function commandNameIsMe(): void
    {
        $this->assertSame('me', $this->command->getName());
    }

    #[Test]
    public function processPreview(): void
    {
        $this->assertSame('', $this->command->processPreview(''));
        $this->assertSame('*runs fast*', $this->command->processPreview('runs fast'));
    }

    #[Test]
    public function executeTransformsText(): void
    {
        $channel = new Channel();
        $user = new User();

        $result = $this->command->execute('jumps', $channel, $user);
        $this->assertNull($result->response);
        $this->assertSame('/me jumps', $result->messageText);
    }
}
