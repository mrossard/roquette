<?php

declare(strict_types=1);

namespace App\Dto\Workspace;

use Symfony\Component\HttpFoundation\Request;

final readonly class CreateWorkspaceDto
{
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $name = trim((string) $request->request->get('name', ''));
        $description = trim((string) $request->request->get('description', ''));

        return new self(name: $name, description: $description !== '' ? $description : null);
    }

    public function isValid(): bool
    {
        return $this->name !== '';
    }
}
