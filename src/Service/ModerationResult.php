<?php

declare(strict_types=1);

namespace App\Service;

final readonly class ModerationResult
{
    public function __construct(
        private string $status,
        private ?string $reason = null,
        private ?string $maskedContent = null,
        private ?string $originalContent = null,
    ) {}

    public function isFlagged(): bool
    {
        return $this->status !== 'clean';
    }

    public function isMasked(): bool
    {
        return $this->status === 'masked';
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getMaskedContent(): ?string
    {
        return $this->maskedContent;
    }

    public function getOriginalContent(): ?string
    {
        return $this->originalContent;
    }

    public static function clean(): self
    {
        return new self(status: 'clean');
    }

    public static function masked(string $maskedContent, string $originalContent, string $reason = "Secret ou clé d'API détecté(e)"): self
    {
        return new self(
            status: 'masked',
            reason: $reason,
            maskedContent: $maskedContent,
            originalContent: $originalContent,
        );
    }

    public static function flagged(string $reason): self
    {
        return new self(
            status: 'flagged',
            reason: $reason,
        );
    }
}
