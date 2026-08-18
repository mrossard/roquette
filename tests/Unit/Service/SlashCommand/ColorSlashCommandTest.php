<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SlashCommand;

use App\Entity\Channel;
use App\Entity\User;
use App\Service\SlashCommand\ColorSlashCommand;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

#[AllowMockObjectsWithoutExpectations]
class ColorSlashCommandTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private Environment $twig;
    private ColorSlashCommand $command;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->twig = $this->createMock(Environment::class);
        $this->command = new ColorSlashCommand($this->entityManager, $this->twig);
    }

    #[Test]
    public function commandNameIsColor(): void
    {
        $this->assertSame('color', $this->command->getName());
        $this->assertNull($this->command->processPreview('120'));
    }

    #[Test]
    public function validHueUpdatesUserAndFlushes(): void
    {
        $user = new User();
        $channel = new Channel();

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('dashboard/_input_form.html.twig', ['activeChannel' => $channel])
            ->willReturn('<form></form>');
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->command->execute('180', $channel, $user);

        $this->assertNotNull($result->response);
        $this->assertSame(200, $result->response->getStatusCode());
        $this->assertSame('true', $result->response->headers->get('HX-Refresh'));
        $this->assertSame(180, $user->getCustomHue());
    }

    #[Test]
    public function invalidHueReturns400(): void
    {
        $user = new User();
        $channel = new Channel();

        $this->entityManager->expects($this->never())->method('flush');

        $result = $this->command->execute('500', $channel, $user);

        $this->assertNotNull($result->response);
        $this->assertSame(400, $result->response->getStatusCode());
    }
}
