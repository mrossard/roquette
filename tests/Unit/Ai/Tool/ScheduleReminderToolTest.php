<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Tool;

use App\Ai\ChannelResolver;
use App\Ai\Tool\ScheduleReminderTool;
use App\Entity\Channel;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\ChannelRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;

class ScheduleReminderToolTest extends TestCase
{
    public function testWorkspaceNameWinsOverGlobalSlug(): void
    {
        $globalChannel = $this->makeChannel('général', 'test');
        $channelRepo = $this->createMock(ChannelRepository::class);
        $channelRepo->expects($this->any())->method('findOneBy')->with(['slug' => 'test'])->willReturn($globalChannel);

        $workspace = new Workspace();
        $workspace->setName('Workspace A');
        $workspace->setSlug('ws-a');
        $workspaceChannel = $this->makeChannel('test', 'sc-test-258258');
        $workspace->addChannel($workspaceChannel);

        $workspaceRepo = $this->createMock(WorkspaceRepository::class);
        $workspaceRepo->expects($this->any())->method('find')->with(46)->willReturn($workspace);

        $tool = $this->buildTool($channelRepo, $workspaceRepo);

        $result = $tool->__invoke('test', 'Relancer docker', 3, 1, 46);

        static::assertStringContainsString('canal #test', $result);
    }

    public function testFallbackToGlobalWithoutWorkspace(): void
    {
        $globalChannel = $this->makeChannel('test', 'test');
        $channelRepo = $this->createMock(ChannelRepository::class);
        $channelRepo->expects($this->any())->method('findOneBy')->with(['slug' => 'test'])->willReturn($globalChannel);

        $workspaceRepo = $this->createMock(WorkspaceRepository::class);
        $tool = $this->buildTool($channelRepo, $workspaceRepo);

        $result = $tool->__invoke('test', 'Relancer docker', 3, null);

        static::assertStringContainsString('canal #test', $result);
    }

    public function testPersonalDmTakesPriority(): void
    {
        $user = new User();
        $user->setUsername('mrossard');
        $user->setSlug('mrossard');

        $dmChannel = $this->makeChannel('Assistant', 'dm-robot-roquette-mrossard');

        $channelRepo = $this->createMock(ChannelRepository::class);
        $channelRepo->expects($this->any())->method('findOneBy')
            ->with(static::logicalOr(
                ['slug' => 'dm-robot-roquette-mrossard'],
                static::anything(),
            ))
            ->willReturn($dmChannel);

        $workspaceRepo = $this->createMock(WorkspaceRepository::class);
        $tool = $this->buildTool($channelRepo, $workspaceRepo, $user);

        $result = $tool->__invoke('assistant', 'Relancer docker', 3, 1);

        static::assertStringContainsString('canal #Assistant', $result);
    }

    private function makeChannel(string $name, string $slug): Channel
    {
        $channel = new Channel();
        $channel->setName($name);
        $channel->setSlug($slug);

        return $channel;
    }

    private function buildTool(
        ChannelRepository $channelRepo,
        WorkspaceRepository $workspaceRepo,
        ?User $foundUser = null,
    ): ScheduleReminderTool {
        $em = $this->createMock(EntityManagerInterface::class);
        $persisted = [];
        $em->expects($this->any())->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            }
        );
        $em->expects($this->any())->method('flush')->willReturnCallback(
            static function () use (&$persisted): void {
                foreach ($persisted as $entity) {
                    if ($entity instanceof \App\Entity\Reminder) {
                        (new \ReflectionProperty(\App\Entity\Reminder::class, 'id'))->setValue($entity, 1);
                    }
                }
            }
        );

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->any())->method('find')->willReturn($foundUser);
        $userRepo->expects($this->any())->method('findOneBy')->willReturn($foundUser);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->any())->method('dispatch')->willReturn(
            new \Symfony\Component\Messenger\Envelope(new \stdClass())
        );

        return new ScheduleReminderTool(
            $em,
            $channelRepo,
            $userRepo,
            $bus,
            new ChannelResolver($channelRepo, $workspaceRepo),
        );
    }
}
