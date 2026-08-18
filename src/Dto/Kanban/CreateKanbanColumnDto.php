<?php

declare(strict_types=1);

namespace App\Dto\Kanban;

use Symfony\Component\HttpFoundation\Request;

final readonly class CreateKanbanColumnDto
{
    public function __construct(
        public int $channelId,
        public string $name,
        public ?string $color = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $channelId = (int) $request->request->get('channelId', 0);
        $name = trim((string) $request->request->get('name', ''));
        $rawColor = (string) $request->request->get('color', '');
        $color = $rawColor !== '' ? $rawColor : null;

        return new self(channelId: $channelId, name: $name, color: $color);
    }

    public function isValid(): bool
    {
        return $this->channelId > 0 && $this->name !== '';
    }
}
