<?php

declare(strict_types=1);

namespace App\Dto\Emoji;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final readonly class UploadEmojiDto
{
    public function __construct(
        public string $code,
        public ?UploadedFile $file,
        public string $tags = '',
    ) {}

    public static function fromRequest(Request $request): self
    {
        $code = trim((string) $request->request->get('code', ''));
        $file = $request->files->get('emoji_file');
        $tags = (string) $request->request->get('tags', '');

        return new self(code: $code, file: $file instanceof UploadedFile ? $file : null, tags: $tags);
    }

    public function isValid(): bool
    {
        return $this->code !== '' && $this->file !== null;
    }
}
