<?php

declare(strict_types=1);

namespace App\Dto\Group;

final readonly class GroupDto
{
    public function __construct(
        public string $identifier,
        public string $name,
        public ?string $description = null,
    ) {}
}
