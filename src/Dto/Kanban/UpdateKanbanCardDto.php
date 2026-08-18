<?php

declare(strict_types=1);

namespace App\Dto\Kanban;

use Symfony\Component\HttpFoundation\Request;

final readonly class UpdateKanbanCardDto
{
    /**
     * @param list<string>|null $labels
     */
    public function __construct(
        public ?int $columnId = null,
        public ?int $userId = null,
        public ?\DateTimeImmutable $dueAt = null,
        public ?string $priority = null,
        public ?array $labels = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            columnId: self::parseColumnId($request),
            userId: self::parseUserId($request),
            dueAt: self::parseDueDate($request),
            priority: self::parsePriority($request),
            labels: self::parseLabels($request),
        );
    }

    public static function parseColumnId(Request $request): ?int
    {
        $all = $request->request->all();
        $columnId = $all['columnId'] ?? null;
        if ($columnId === null || $columnId === '' || $columnId === 'null') {
            return null;
        }

        return (int) $columnId;
    }

    public static function parseUserId(Request $request): ?int
    {
        $all = $request->request->all();
        $userId = $all['userId'] ?? null;
        if ($userId === null || $userId === '' || $userId === 'null') {
            return null;
        }

        return (int) $userId;
    }

    public static function parseDueDate(Request $request): ?\DateTimeImmutable
    {
        $all = $request->request->all();
        $dueAtStr = $all['dueAt'] ?? null;
        if ($dueAtStr === null || (string) $dueAtStr === '') {
            return null;
        }

        $parsedDate = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $dueAtStr);

        return $parsedDate !== false ? $parsedDate : null;
    }

    public static function parsePriority(Request $request): ?string
    {
        $all = $request->request->all();
        $rawPriority = (string) ($all['priority'] ?? '');

        return $rawPriority !== '' ? $rawPriority : null;
    }

    /**
     * @return list<string>|null
     */
    public static function parseLabels(Request $request): ?array
    {
        $all = $request->request->all();
        $labelsReq = $all['labels'] ?? null;
        $labels = match (true) {
            is_array($labelsReq) => $labelsReq,
            is_string($labelsReq) && trim($labelsReq) !== '' => explode(',', $labelsReq),
            default => [],
        };

        $filtered = array_values(array_filter(array_map('trim', $labels), static fn(string $label): bool => $label !== ''));

        return $filtered !== [] ? $filtered : null;
    }
}
