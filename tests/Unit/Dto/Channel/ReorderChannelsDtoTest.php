<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\Channel;

use App\Dto\Channel\ReorderChannelsDto;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ReorderChannelsDtoTest extends TestCase
{
    #[Test]
    public function reorderChannelsDtoParsesJsonBody(): void
    {
        $json = json_encode(['order' => [3, 1, 2]]);
        $request = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);

        $dto = ReorderChannelsDto::fromRequest($request);

        $this->assertTrue($dto->isValid());
        $this->assertSame([3, 1, 2], $dto->order);
    }

    #[Test]
    public function reorderChannelsDtoParsesFormData(): void
    {
        $request = new Request([], ['order' => ['5', '6', '7']]);

        $dto = ReorderChannelsDto::fromRequest($request);

        $this->assertTrue($dto->isValid());
        $this->assertSame([5, 6, 7], $dto->order);
    }

    #[Test]
    public function reorderChannelsDtoHandlesEmptyOrInvalidData(): void
    {
        $request = new Request();
        $dto = ReorderChannelsDto::fromRequest($request);

        $this->assertFalse($dto->isValid());
        $this->assertSame([], $dto->order);
    }
}
