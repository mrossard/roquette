<?php

declare(strict_types=1);

namespace App\Dto\Message;

use App\Entity\Message;

final readonly class EditResult
{
    public function __construct(
        public bool $success,
        public ?Message $message = null,
        public ?string $renderedHtml = null,
        public ?string $error = null,
        public int $statusCode = 200,
    ) {}

    public static function ok(Message $message, string $renderedHtml): self
    {
        return new self(success: true, message: $message, renderedHtml: $renderedHtml, statusCode: 200);
    }

    public static function error(string $error, ?Message $message = null, int $statusCode = 400): self
    {
        return new self(success: false, message: $message, error: $error, statusCode: $statusCode);
    }
}
