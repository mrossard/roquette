<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\Channel;

use App\Dto\Channel\CreateChannelDto;
use App\Dto\Channel\UpdateChannelDto;
use App\Entity\Workspace;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ChannelDtoTest extends TestCase
{
    #[Test]
    public function createChannelDtoFactoryHandlesDefaults(): void
    {
        $dto = CreateChannelDto::fromNameDescriptionAndExtra('general', 'General talk');

        $this->assertSame('general', $dto->name);
        $this->assertSame('General talk', $dto->description);
        $this->assertFalse($dto->isPrivate);
        $this->assertSame('', $dto->groupIdentifier);
        $this->assertFalse($dto->isGroupChannel);
        $this->assertFalse($dto->isTodoList);
        $this->assertSame(6, $dto->retentionMonths);
        $this->assertNull($dto->workspace);
    }

    #[Test]
    public function createChannelDtoFactoryHandlesCustomValues(): void
    {
        $workspace = $this->createMock(Workspace::class);

        $dto = CreateChannelDto::fromNameDescriptionAndExtra('project', 'Project tasks', [
            'isPrivate' => true,
            'groupIdentifier' => 'team-backend',
            'isGroupChannel' => true,
            'isTodoList' => true,
            'retentionMonths' => '12',
            'workspace' => $workspace,
        ]);

        $this->assertSame('project', $dto->name);
        $this->assertSame('Project tasks', $dto->description);
        $this->assertTrue($dto->isPrivate);
        $this->assertSame('team-backend', $dto->groupIdentifier);
        $this->assertTrue($dto->isGroupChannel);
        $this->assertTrue($dto->isTodoList);
        $this->assertSame(12, $dto->retentionMonths);
        $this->assertSame($workspace, $dto->workspace);
    }

    #[Test]
    public function createChannelDtoFromRequest(): void
    {
        $workspace = $this->createMock(Workspace::class);
        $request = new \Symfony\Component\HttpFoundation\Request(request: [
            'name' => '  Dev Team  ',
            'description' => 'Channel description',
            'isPrivate' => '1',
            'isTodoList' => '1',
            'messageRetentionMonths' => '3',
        ]);

        $dto = CreateChannelDto::fromRequest($request, $workspace);

        $this->assertSame('Dev Team', $dto->name);
        $this->assertSame('Channel description', $dto->description);
        $this->assertTrue($dto->isPrivate);
        $this->assertTrue($dto->isTodoList);
        $this->assertSame(3, $dto->retentionMonths);
        $this->assertSame($workspace, $dto->workspace);
        $this->assertTrue($dto->isValid());
    }

    #[Test]
    public function updateChannelDtoFactoryHandlesValues(): void
    {
        $dto = UpdateChannelDto::fromNameDescriptionAndExtra('new-name', 'new-desc', [
            'isTodoList' => true,
            'retentionMonths' => 0,
            'administratorIds' => [1, '2', 3],
        ]);

        $this->assertSame('new-name', $dto->name);
        $this->assertSame('new-desc', $dto->description);
        $this->assertTrue($dto->isTodoList);
        $this->assertNull($dto->retentionMonths);
        $this->assertSame([1, '2', 3], $dto->administratorIds);
    }
}
