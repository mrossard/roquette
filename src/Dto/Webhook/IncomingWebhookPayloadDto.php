<?php

declare(strict_types=1);

namespace App\Dto\Webhook;

use Symfony\Component\HttpFoundation\Request;

final readonly class IncomingWebhookPayloadDto
{
    public const int MAX_PAYLOAD_SIZE = 100_000;

    public function __construct(
        public ?string $content,
        public ?string $customAuthorName = null,
        public ?string $customAuthorAvatar = null,
        public bool $isPayloadTooLarge = false,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $contentLength = (int) $request->headers->get('Content-Length', '0');
        $rawBody = $request->getContent();

        if ($contentLength > self::MAX_PAYLOAD_SIZE || strlen($rawBody) > self::MAX_PAYLOAD_SIZE) {
            return new self(content: null, isPayloadTooLarge: true);
        }

        /** @var mixed $data */
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            return new self(content: null);
        }

        $rawContent = $data['text'] ?? $data['content'] ?? null;
        $content = null;
        if ($rawContent !== null) {
            $trimmed = trim((string) $rawContent);
            if ($trimmed !== '') {
                $content = $trimmed;
            }
        }

        $customName = $data['username'] ?? $data['customAuthorName'] ?? null;
        $customAvatar = $data['avatar_url'] ?? $data['customAuthorAvatar'] ?? null;

        $nameStr = $customName !== null ? trim((string) $customName) : '';
        $avatarStr = $customAvatar !== null ? trim((string) $customAvatar) : '';

        return new self(
            content: $content,
            customAuthorName: $nameStr !== '' ? $nameStr : null,
            customAuthorAvatar: $avatarStr !== '' ? $avatarStr : null,
        );
    }

    public function hasValidContent(): bool
    {
        return $this->content !== null && $this->content !== '';
    }
}
