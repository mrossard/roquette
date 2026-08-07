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
use App\Service\ChannelAccessService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
class ScheduleReminderToolTest extends TestCase
{
    public function testWorkspaceNameWinsOverGlobalSlug(): void
    {
        $globalChannel = $this->makeChannel('général', 'test');
        $channelRepo = $this->createMock(ChannelRepository::class);
        $channelRepo->method('findOneBy')->willReturn($globalChannel);

        $workspace = new Workspace();
        $workspace->setName('Workspace A');
        $workspace->setSlug('ws-a');
        $workspaceChannel = $this->makeChannel('test', 'sc-test-258258');
        $workspace->addChannel($workspaceChannel);

        $workspaceRepo = $this->createMock(WorkspaceRepository::class);
        $workspaceRepo->method('find')->willReturn($workspace);

        $tool = $this->buildTool($channelRepo, $workspaceRepo);

        $result = $tool->__invoke('test', 'Relancer docker', 3, 1, 46);

        static::assertStringContainsString('canal #test', $result);
    }

    public function testFallbackToGlobalWithoutWorkspace(): void
    {
        $globalChannel = $this->makeChannel('test', 'test');
        $channelRepo = $this->createMock(ChannelRepository::class);
        $channelRepo->method('findOneBy')->willReturn($globalChannel);

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
        $channelRepo->method('findOneBy')->willReturn($dmChannel);

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
        ?ChannelAccessService $accessService = null,
    ): ScheduleReminderTool {
        if ($accessService === null) {
            $accessService = $this->createMock(ChannelAccessService::class);
            $accessService->method('canUserAccess')->willReturn(true);
        }

        $em = $this->createMock(EntityManagerInterface::class);
        $persisted = [];
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $em->method('flush')->willReturnCallback(static function () use (&$persisted): void {
            foreach ($persisted as $entity) {
                if (!$entity instanceof \App\Entity\Reminder) {
                    continue;
                }

                new \ReflectionProperty(\App\Entity\Reminder::class, 'id')->setValue($entity, 1);
            }
        });

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('find')->willReturn($foundUser);
        $userRepo->method('findOneBy')->willReturn($foundUser);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturn(new \Symfony\Component\Messenger\Envelope(new \stdClass()));

        return new ScheduleReminderTool(
            $em,
            $userRepo,
            $bus,
            new ChannelResolver($channelRepo, $workspaceRepo),
            $accessService,
        );
    }

    public function testReminderDeniedWhenUserHasNoAccess(): void
    {
        $user = new User();
        $user->setUsername('mrossard');
        $user->setSlug('mrossard');

        $channel = $this->makeChannel('private', 'private');
        $channelRepo = $this->createMock(ChannelRepository::class);
        $channelRepo->method('findOneBy')->willReturn($channel);

        $workspaceRepo = $this->createMock(WorkspaceRepository::class);

        $accessService = $this->createMock(ChannelAccessService::class);
        $accessService->method('canUserAccess')->willReturn(false);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('find')->willReturn($user);

        $bus = $this->createMock(MessageBusInterface::class);

        $tool = new ScheduleReminderTool(
            $em,
            $userRepo,
            $bus,
            new ChannelResolver($channelRepo, $workspaceRepo),
            $accessService,
        );

        $result = $tool->__invoke('private', 'Relancer docker', 3, 1);

        static::assertStringContainsString('pas accès', $result);
    }
}
