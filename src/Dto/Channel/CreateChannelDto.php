<?php

declare(strict_types=1);

namespace App\Dto\Channel;

use App\Entity\Workspace;

final readonly class CreateChannelDto
{
    public function __construct(
        public string $name,
        public string $description = '',
        public bool $isPrivate = false,
        public string $groupIdentifier = '',
        public bool $isGroupChannel = false,
        public bool $isTodoList = false,
        public ?int $retentionMonths = 6,
        public ?Workspace $workspace = null,
    ) {}

    /**
     * @param array<string, mixed> $extra
     */
    public static function fromNameDescriptionAndExtra(string $name, string $description, array $extra = []): self
    {
        $retention = $extra['retentionMonths'] ?? null;
        $retentionMonths = $retention !== null && $retention !== '' ? (int) $retention : 6;

        return new self(
            name: $name,
            description: $description,
            isPrivate: (bool) ($extra['isPrivate'] ?? false),
            groupIdentifier: (string) ($extra['groupIdentifier'] ?? ''),
            isGroupChannel: (bool) ($extra['isGroupChannel'] ?? false),
            isTodoList: (bool) ($extra['isTodoList'] ?? false),
            retentionMonths: $retentionMonths === 0 ? null : $retentionMonths,
            workspace: ($extra['workspace'] ?? null) instanceof Workspace ? $extra['workspace'] : null,
        );
    }

    public static function fromRequest(
        \Symfony\Component\HttpFoundation\Request $request,
        ?Workspace $workspace = null,
    ): self {
        $name = trim((string) $request->request->get('name', ''));
        $description = trim((string) $request->request->get('description', ''));

        return self::fromNameDescriptionAndExtra($name, $description, [
            'isPrivate' => $request->request->getBoolean('isPrivate', false),
            'groupIdentifier' => (string) $request->request->get('groupIdentifier', ''),
            'isGroupChannel' => $request->request->getBoolean('isGroupChannel', false),
            'isTodoList' => $request->request->getBoolean('isTodoList', false),
            'retentionMonths' => $request->request->get('messageRetentionMonths'),
            'workspace' => $workspace,
        ]);
    }

    public function isValid(): bool
    {
        return $this->name !== '';
    }
}
