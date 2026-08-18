<?php

declare(strict_types=1);

namespace App\Dto\Group;

use Symfony\Component\HttpFoundation\Request;

final readonly class ImportAdminGroupDto
{
    public function __construct(
        public string $identifier,
        public string $name,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $identifier = trim((string) $request->request->get('identifier', ''));
        $name = trim((string) $request->request->get('name', ''));

        return new self(identifier: $identifier, name: $name);
    }

    public function isValid(): bool
    {
        return $this->identifier !== '' && $this->name !== '';
    }
}
