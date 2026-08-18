<?php

declare(strict_types=1);

namespace App\Dto\Webhook;

use Symfony\Component\HttpFoundation\Request;

final readonly class CreateWebhookDto
{
    public function __construct(
        public string $name,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $name = trim((string) $request->request->get('name', ''));

        return new self(name: $name);
    }

    public function isValid(): bool
    {
        return $this->name !== '';
    }
}
