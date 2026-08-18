<?php

declare(strict_types=1);

namespace App\Dto\Channel;

use Symfony\Component\HttpFoundation\Request;

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

    public static function fromRequest(Request $request): self
    {
        $name = trim((string) $request->request->get('name', ''));
        $description = trim((string) $request->request->get('description', ''));

        return self::fromNameDescriptionAndExtra($name, $description, [
            'isTodoList' => $request->request->getBoolean('isTodoList', false),
            'retentionMonths' => $request->request->get('messageRetentionMonths'),
            'administratorIds' => $request->request->all('administrators'),
        ]);
    }

    public function isValid(): bool
    {
        return $this->name !== '';
    }

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
