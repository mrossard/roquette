<?php

declare(strict_types=1);

namespace App\Dto\Link;

/**
 * Represents Open Graph or direct image link preview metadata.
 * Implements \ArrayAccess for backwards compatibility with Twig and controllers.
 *
 * @implements \ArrayAccess<string, mixed>
 */
final readonly class LinkPreviewDto implements \ArrayAccess
{
    public function __construct(
        public string $url,
        public string $type = 'og_preview',
        public ?string $title = null,
        public ?string $description = null,
        public ?string $image = null,
        public ?string $siteName = null,
    ) {}

    public function isDirectImage(): bool
    {
        return $this->type === 'direct_image';
    }

    public static function directImage(string $url): self
    {
        return new self(url: $url, type: 'direct_image');
    }

    public static function ogPreview(
        string $url,
        ?string $title = null,
        ?string $description = null,
        ?string $image = null,
        ?string $siteName = null,
    ): self {
        return new self(
            url: $url,
            type: 'og_preview',
            title: $title,
            description: $description,
            image: $image,
            siteName: $siteName,
        );
    }

    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, ['url', 'type', 'title', 'description', 'image', 'siteName'], true);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'url' => $this->url,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'image' => $this->image,
            'siteName' => $this->siteName,
            default => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \BadMethodCallException('LinkPreviewDto is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \BadMethodCallException('LinkPreviewDto is immutable.');
    }

    /**
     * @return array{url: string, type: string, title: ?string, description: ?string, image: ?string, siteName: ?string}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'image' => $this->image,
            'siteName' => $this->siteName,
        ];
    }
}
