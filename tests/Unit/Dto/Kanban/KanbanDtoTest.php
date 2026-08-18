<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\Kanban;

use App\Dto\Kanban\CreateKanbanColumnDto;
use App\Dto\Kanban\ReorderKanbanColumnsDto;
use App\Dto\Kanban\UpdateKanbanCardDto;
use App\Dto\Kanban\UpdateKanbanColumnDto;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class KanbanDtoTest extends TestCase
{
    #[Test]
    public function createKanbanColumnDtoValid(): void
    {
        $request = new Request(request: [
            'channelId' => '42',
            'name' => '  In Progress  ',
            'color' => '#ff0000',
        ]);

        $dto = CreateKanbanColumnDto::fromRequest($request);

        static::assertSame(42, $dto->channelId);
        static::assertSame('In Progress', $dto->name);
        static::assertSame('#ff0000', $dto->color);
        static::assertTrue($dto->isValid());
    }

    #[Test]
    public function createKanbanColumnDtoEmptyNameOrInvalidChannel(): void
    {
        $request = new Request(request: [
            'channelId' => '0',
            'name' => '   ',
            'color' => '',
        ]);

        $dto = CreateKanbanColumnDto::fromRequest($request);

        static::assertSame(0, $dto->channelId);
        static::assertSame('', $dto->name);
        static::assertNull($dto->color);
        static::assertFalse($dto->isValid());
    }

    #[Test]
    public function updateKanbanColumnDtoValidAndInvalid(): void
    {
        $validRequest = new Request(request: ['name' => '  Done  ']);
        $validDto = UpdateKanbanColumnDto::fromRequest($validRequest);
        static::assertSame('Done', $validDto->name);
        static::assertTrue($validDto->isValid());

        $invalidRequest = new Request(request: ['name' => '   ']);
        $invalidDto = UpdateKanbanColumnDto::fromRequest($invalidRequest);
        static::assertSame('', $invalidDto->name);
        static::assertFalse($invalidDto->isValid());
    }

    #[Test]
    public function reorderKanbanColumnsDtoValidAndInvalid(): void
    {
        $validRequest = new Request(request: ['columnIds' => ['1', '2', '3']]);
        $validDto = ReorderKanbanColumnsDto::fromRequest($validRequest);
        static::assertSame([1, 2, 3], $validDto->columnIds);
        static::assertTrue($validDto->isValid());

        $emptyRequest = new Request(request: ['columnIds' => []]);
        $emptyDto = ReorderKanbanColumnsDto::fromRequest($emptyRequest);
        static::assertSame([], $emptyDto->columnIds);
        static::assertFalse($emptyDto->isValid());

        $nonArrayRequest = new Request(request: ['columnIds' => 'invalid']);
        $nonArrayDto = ReorderKanbanColumnsDto::fromRequest($nonArrayRequest);
        static::assertSame([], $nonArrayDto->columnIds);
        static::assertFalse($nonArrayDto->isValid());
    }

    #[Test]
    public function updateKanbanCardDtoParsing(): void
    {
        $request = new Request(request: [
            'columnId' => '10',
            'userId' => '5',
            'dueAt' => '2026-12-31',
            'priority' => 'high',
            'labels' => 'bug, urgent, frontend',
        ]);

        $dto = UpdateKanbanCardDto::fromRequest($request);

        static::assertSame(10, $dto->columnId);
        static::assertSame(5, $dto->userId);
        static::assertSame('2026-12-31', $dto->dueAt?->format('Y-m-d'));
        static::assertSame('high', $dto->priority);
        static::assertSame(['bug', 'urgent', 'frontend'], $dto->labels);
    }

    #[Test]
    public function updateKanbanCardDtoNullAndEdgeCases(): void
    {
        $request = new Request(request: [
            'columnId' => 'null',
            'userId' => '',
            'dueAt' => 'invalid-date',
            'priority' => '',
            'labels' => ['', '  '],
        ]);

        $dto = UpdateKanbanCardDto::fromRequest($request);

        static::assertNull($dto->columnId);
        static::assertNull($dto->userId);
        static::assertNull($dto->dueAt);
        static::assertNull($dto->priority);
        static::assertNull($dto->labels);
    }

    #[Test]
    public function updateKanbanCardDtoLabelsArray(): void
    {
        $request = new Request(request: [
            'labels' => ['backend', ' api '],
        ]);

        $labels = UpdateKanbanCardDto::parseLabels($request);
        static::assertSame(['backend', 'api'], $labels);
    }
}
