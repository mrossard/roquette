<?php

declare(strict_types=1);

namespace App\Dto\Emoji;

use Symfony\Component\HttpFoundation\Request;

final readonly class EditEmojiTagsDto
{
    public function __construct(
        public string $code,
        public string $tags = '',
    ) {}

    public static function fromRequest(Request $request): self
    {
        $code = (string) $request->request->get('code', '');
        $tags = (string) $request->request->get('tags', '');

        return new self(code: $code, tags: $tags);
    }

    public function isValid(): bool
    {
        return $this->code !== '';
    }
}
