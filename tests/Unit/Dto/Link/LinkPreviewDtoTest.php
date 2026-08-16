<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\Link;

use App\Dto\Link\LinkPreviewDto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class LinkPreviewDtoTest extends TestCase
{
    #[Test]
    public function directImageDto(): void
    {
        $dto = LinkPreviewDto::directImage('https://example.com/image.png');

        $this->assertSame('https://example.com/image.png', $dto->url);
        $this->assertSame('direct_image', $dto->type);
        $this->assertTrue($dto->isDirectImage());
        $this->assertNull($dto->title);

        $this->assertSame('https://example.com/image.png', $dto['url']);
        $this->assertSame('direct_image', $dto['type']);
    }

    #[Test]
    public function ogPreviewDto(): void
    {
        $dto = LinkPreviewDto::ogPreview(
            url: 'https://example.com/article',
            title: 'My Article',
            description: 'A great article',
            image: 'https://example.com/cover.jpg',
            siteName: 'Example News',
        );

        $this->assertSame('https://example.com/article', $dto->url);
        $this->assertSame('og_preview', $dto->type);
        $this->assertFalse($dto->isDirectImage());
        $this->assertSame('My Article', $dto->title);
        $this->assertSame('A great article', $dto->description);
        $this->assertSame('https://example.com/cover.jpg', $dto->image);
        $this->assertSame('Example News', $dto->siteName);

        $this->assertSame([
            'url' => 'https://example.com/article',
            'type' => 'og_preview',
            'title' => 'My Article',
            'description' => 'A great article',
            'image' => 'https://example.com/cover.jpg',
            'siteName' => 'Example News',
        ], $dto->toArray());
    }
}
