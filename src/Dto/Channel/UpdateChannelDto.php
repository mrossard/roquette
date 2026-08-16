<?php

declare(strict_types=1);

namespace App\Dto\Channel;

final readonly class UpdateChannelDto
{
    /**
     * @param list<string|int> $administratorIds
     */
    public function __construct(
        public string $name,
        public string $description = '',
        public bool $isTodoList = false,
        public ?int $retentionMonths = 6,
        public array $administratorIds = [],
    ) {}

    /**
     * @param array<string, mixed> $extra
     */
    public static function fromNameDescriptionAndExtra(
        string $name,
        string $description,
        array $extra = [],
    ): self {
        $retention = $extra['retentionMonths'] ?? null;
        $retentionMonths = ($retention !== null && $retention !== '') ? (int) $retention : 6;

        return new self(
            name: $name,
            description: $description,
            isTodoList: (bool) ($extra['isTodoList'] ?? false),
            retentionMonths: $retentionMonths === 0 ? null : $retentionMonths,
            administratorIds: array_values((array) ($extra['administratorIds'] ?? [])),
        );
    }
}
