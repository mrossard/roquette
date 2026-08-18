<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\Workspace;

use App\Dto\Workspace\CreateWorkspaceDto;
use App\Dto\Workspace\UpdateWorkspaceDto;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

class WorkspaceDtoTest extends TestCase
{
    #[Test]
    public function createWorkspaceDtoFromRequestValid(): void
    {
        $request = new Request(request: [
            'name' => '  Mon Workspace  ',
            'description' => '  Description ici  ',
        ]);

        $dto = CreateWorkspaceDto::fromRequest($request);

        static::assertSame('Mon Workspace', $dto->name);
        static::assertSame('Description ici', $dto->description);
        static::assertTrue($dto->isValid());
    }

    #[Test]
    public function createWorkspaceDtoFromRequestEmpty(): void
    {
        $request = new Request(request: [
            'name' => '   ',
            'description' => '',
        ]);

        $dto = CreateWorkspaceDto::fromRequest($request);

        static::assertSame('', $dto->name);
        static::assertNull($dto->description);
        static::assertFalse($dto->isValid());
    }

    #[Test]
    public function updateWorkspaceDtoFromRequest(): void
    {
        $file = $this->createStub(UploadedFile::class);
        $request = new Request(request: [
            'name' => 'Updated Name',
            'description' => 'Updated Description',
            'delete_avatar' => '1',
        ], files: [
            'avatar' => $file,
        ]);

        $dto = UpdateWorkspaceDto::fromRequest($request);

        static::assertSame('Updated Name', $dto->name);
        static::assertSame('Updated Description', $dto->description);
        static::assertTrue($dto->deleteAvatar);
        static::assertSame($file, $dto->avatarFile);
        static::assertTrue($dto->isValid());
    }
}
