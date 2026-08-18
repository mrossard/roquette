<?php

declare(strict_types=1);

namespace App\Dto\Group;

use Symfony\Component\HttpFoundation\Request;

final readonly class CreateAdminGroupDto
{
    public function __construct(
        public string $name,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $name = trim((string) $request->request->get('name', ''));

        return new self(name: $name);
    }

    public function isValid(): bool
    {
        return $this->name !== '';
    }
}
