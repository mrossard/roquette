<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\Emoji;

use App\Dto\Emoji\DeleteEmojiDto;
use App\Dto\Emoji\EditEmojiTagsDto;
use App\Dto\Emoji\EmojiTagDto;
use App\Dto\Emoji\UploadEmojiDto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

class AdminEmojiDtoTest extends TestCase
{
    public function testUploadEmojiDtoValid(): void
    {
        $file = new UploadedFile(
            path: tempnam(sys_get_temp_dir(), 'test_emoji_'),
            originalName: 'custom.png',
            mimeType: 'image/png',
            error: null,
            test: true,
        );

        $request = new Request([], ['code' => '  rocket  ', 'tags' => 'tag1, tag2'], [], [], ['emoji_file' => $file]);
        $dto = UploadEmojiDto::fromRequest($request);

        static::assertSame('rocket', $dto->code);
        static::assertSame($file, $dto->file);
        static::assertSame('tag1, tag2', $dto->tags);
        static::assertTrue($dto->isValid());
    }

    public function testUploadEmojiDtoInvalidWhenMissingCodeOrFile(): void
    {
        $file = new UploadedFile(
            path: tempnam(sys_get_temp_dir(), 'test_emoji_'),
            originalName: 'custom.png',
            mimeType: 'image/png',
            error: null,
            test: true,
        );

        $requestWithoutCode = new Request([], ['code' => '   '], [], [], ['emoji_file' => $file]);
        $dto1 = UploadEmojiDto::fromRequest($requestWithoutCode);
        static::assertFalse($dto1->isValid());

        $requestWithoutFile = new Request([], ['code' => 'rocket']);
        $dto2 = UploadEmojiDto::fromRequest($requestWithoutFile);
        static::assertFalse($dto2->isValid());
    }

    public function testEditEmojiTagsDtoValid(): void
    {
        $request = new Request([], ['code' => 'rocket', 'tags' => 'fun, fast']);
        $dto = EditEmojiTagsDto::fromRequest($request);

        static::assertSame('rocket', $dto->code);
        static::assertSame('fun, fast', $dto->tags);
        static::assertTrue($dto->isValid());
    }

    public function testEditEmojiTagsDtoInvalidWhenCodeEmpty(): void
    {
        $request = new Request([], ['code' => '', 'tags' => 'fun']);
        $dto = EditEmojiTagsDto::fromRequest($request);

        static::assertFalse($dto->isValid());
    }

    public function testEmojiTagDtoValid(): void
    {
        $request = new Request([], ['code' => 'rocket', 'tag' => '  space  ']);
        $dto = EmojiTagDto::fromRequest($request);

        static::assertSame('rocket', $dto->code);
        static::assertSame('space', $dto->tag);
        static::assertTrue($dto->isValid());
    }

    public function testEmojiTagDtoInvalidWhenMissingCodeOrTag(): void
    {
        $request1 = new Request([], ['code' => '', 'tag' => 'space']);
        $dto1 = EmojiTagDto::fromRequest($request1);
        static::assertFalse($dto1->isValid());

        $request2 = new Request([], ['code' => 'rocket', 'tag' => '   ']);
        $dto2 = EmojiTagDto::fromRequest($request2);
        static::assertFalse($dto2->isValid());
    }

    public function testDeleteEmojiDtoValid(): void
    {
        $request = new Request([], ['code' => 'rocket']);
        $dto = DeleteEmojiDto::fromRequest($request);

        static::assertSame('rocket', $dto->code);
        static::assertTrue($dto->isValid());
    }

    public function testDeleteEmojiDtoInvalidWhenEmpty(): void
    {
        $request = new Request([], ['code' => '']);
        $dto = DeleteEmojiDto::fromRequest($request);

        static::assertFalse($dto->isValid());
    }
}
