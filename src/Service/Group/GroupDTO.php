<?php

declare(strict_types=1);

namespace App\Service\Group;

class GroupDTO
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $name,
        public readonly ?string $description = null,
    ) {}
}
