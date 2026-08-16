<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Response;

final readonly class SlashCommandResult
{
    public function __construct(
        public string $messageText,
        public ?Response $response = null,
    ) {}

    public static function handled(Response $response): self
    {
        return new self(messageText: '', response: $response);
    }

    public static function transformed(string $messageText): self
    {
        return new self(messageText: $messageText, response: null);
    }

    public static function unhandled(string $messageText): self
    {
        return new self(messageText: $messageText, response: null);
    }
}
