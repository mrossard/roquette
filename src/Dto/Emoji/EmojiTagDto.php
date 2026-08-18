<?php

declare(strict_types=1);

namespace App\Dto\Emoji;

use Symfony\Component\HttpFoundation\Request;

final readonly class EmojiTagDto
{
    public function __construct(
        public string $code,
        public string $tag,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $code = (string) $request->request->get('code', '');
        $tag = trim((string) $request->request->get('tag', ''));

        return new self(
            code: $code,
            tag: $tag,
        );
    }

    public function isValid(): bool
    {
        return $this->code !== '' && $this->tag !== '';
    }
}
