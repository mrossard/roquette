<?php

declare(strict_types=1);

namespace App\Dto\Kanban;

use Symfony\Component\HttpFoundation\Request;

final readonly class ReorderKanbanColumnsDto
{
    /**
     * @param list<int> $columnIds
     */
    public function __construct(
        public array $columnIds,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $all = $request->request->all();
        $rawColumnIds = $all['columnIds'] ?? null;
        if (!is_array($rawColumnIds)) {
            return new self(columnIds: []);
        }

        $columnIds = array_values(array_map('intval', array_filter($rawColumnIds, static fn($v): bool => is_numeric(
            $v,
        ))));

        return new self(columnIds: $columnIds);
    }

    public function isValid(): bool
    {
        return $this->columnIds !== [];
    }
}
