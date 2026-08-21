<?php

declare(strict_types=1);

namespace App\Dto\Emoji;

use Symfony\Component\HttpFoundation\Request;

final readonly class DeleteEmojiDto
{
    public function __construct(
        public string $code,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(code: (string) $request->request->get('code', ''));
    }

    public function isValid(): bool
    {
        return $this->code !== '';
    }
}
