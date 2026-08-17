<?php

declare(strict_types=1);

namespace App\Dto\Workspace;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final readonly class UpdateWorkspaceDto
{
    public function __construct(
        public string $name,
        public ?string $description = null,
        public bool $deleteAvatar = false,
        public ?UploadedFile $avatarFile = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $name = trim((string) $request->request->get('name', ''));
        $description = trim((string) $request->request->get('description', ''));
        $avatarFile = $request->files->get('avatar');

        return new self(
            name: $name,
            description: $description !== '' ? $description : null,
            deleteAvatar: $request->request->has('delete_avatar'),
            avatarFile: $avatarFile instanceof UploadedFile ? $avatarFile : null,
        );
    }

    public function isValid(): bool
    {
        return $this->name !== '';
    }
}
