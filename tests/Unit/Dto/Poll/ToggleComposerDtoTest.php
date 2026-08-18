<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\Poll;

use App\Dto\Poll\ToggleComposerDto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[AllowMockObjectsWithoutExpectations]
class ToggleComposerDtoTest extends TestCase
{
    #[Test]
    public function toggleComposerDtoHandlesOpenState(): void
    {
        $request = new Request(query: ['open' => '1', 'message' => 'Hello'], request: [
            'poll_question' => 'Your favorite?',
            'poll_options' => ['Option A', 'Option B'],
            'allow_multiple' => '1',
        ]);

        $dto = ToggleComposerDto::fromRequest($request);

        static::assertTrue($dto->open);
        static::assertSame('Hello', $dto->messageValue);
        static::assertSame('Your favorite?', $dto->pollQuestion);
        static::assertSame(['Option A', 'Option B'], $dto->pollOptions);
        static::assertTrue($dto->allowMultiple);
    }

    #[Test]
    public function toggleComposerDtoResetsFieldsWhenClosed(): void
    {
        $request = new Request(query: ['open' => '0', 'message' => 'Draft text'], request: [
            'poll_question' => 'Old question',
            'poll_options' => ['Old Option'],
        ]);

        $dto = ToggleComposerDto::fromRequest($request);

        static::assertFalse($dto->open);
        static::assertSame('Draft text', $dto->messageValue);
        static::assertSame('', $dto->pollQuestion);
        static::assertSame([], $dto->pollOptions);
        static::assertFalse($dto->allowMultiple);
    }
}
