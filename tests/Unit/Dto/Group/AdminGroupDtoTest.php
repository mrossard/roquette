<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\Group;

use App\Dto\Group\CreateAdminGroupDto;
use App\Dto\Group\ImportAdminGroupDto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class AdminGroupDtoTest extends TestCase
{
    public function testCreateAdminGroupDtoValid(): void
    {
        $request = new Request([], ['name' => '  Dev Team  ']);
        $dto = CreateAdminGroupDto::fromRequest($request);

        static::assertSame('Dev Team', $dto->name);
        static::assertTrue($dto->isValid());
    }

    public function testCreateAdminGroupDtoInvalidWhenEmpty(): void
    {
        $request = new Request([], ['name' => '   ']);
        $dto = CreateAdminGroupDto::fromRequest($request);

        static::assertSame('', $dto->name);
        static::assertFalse($dto->isValid());
    }

    public function testImportAdminGroupDtoValid(): void
    {
        $request = new Request([], ['identifier' => '  ext-group-123  ', 'name' => '  External Group  ']);
        $dto = ImportAdminGroupDto::fromRequest($request);

        static::assertSame('ext-group-123', $dto->identifier);
        static::assertSame('External Group', $dto->name);
        static::assertTrue($dto->isValid());
    }

    public function testImportAdminGroupDtoInvalidWhenMissingFields(): void
    {
        $request1 = new Request([], ['identifier' => '', 'name' => 'Valid Name']);
        $dto1 = ImportAdminGroupDto::fromRequest($request1);
        static::assertFalse($dto1->isValid());

        $request2 = new Request([], ['identifier' => 'ext-123', 'name' => '']);
        $dto2 = ImportAdminGroupDto::fromRequest($request2);
        static::assertFalse($dto2->isValid());
    }
}
