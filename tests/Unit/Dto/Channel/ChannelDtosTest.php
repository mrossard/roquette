<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\Channel;

use App\Dto\Channel\UpdateChannelDto;
use App\Dto\Channel\UpdateRetentionDto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[AllowMockObjectsWithoutExpectations]
class ChannelDtosTest extends TestCase
{
    #[Test]
    public function updateRetentionDtoParsesValuesCorrectly(): void
    {
        $req1 = new Request(request: ['messageRetentionMonths' => '12']);
        $dto1 = UpdateRetentionDto::fromRequest($req1);
        static::assertSame(12, $dto1->retentionMonths);

        $req2 = new Request(request: ['messageRetentionMonths' => '0']);
        $dto2 = UpdateRetentionDto::fromRequest($req2);
        static::assertNull($dto2->retentionMonths);

        $req3 = new Request();
        $dto3 = UpdateRetentionDto::fromRequest($req3);
        static::assertNull($dto3->retentionMonths);
    }

    #[Test]
    public function updateChannelDtoParsesRequestAndValidates(): void
    {
        $req1 = new Request(request: [
            'name' => 'General Updated',
            'description' => 'New Description',
            'isTodoList' => '1',
            'messageRetentionMonths' => '6',
            'administrators' => ['1', '2'],
        ]);

        $dto1 = UpdateChannelDto::fromRequest($req1);
        static::assertTrue($dto1->isValid());
        static::assertSame('General Updated', $dto1->name);
        static::assertSame('New Description', $dto1->description);
        static::assertTrue($dto1->isTodoList);
        static::assertSame(6, $dto1->retentionMonths);
        static::assertSame(['1', '2'], $dto1->administratorIds);

        $req2 = new Request(request: ['name' => '   ']);
        $dto2 = UpdateChannelDto::fromRequest($req2);
        static::assertFalse($dto2->isValid());
    }
}
