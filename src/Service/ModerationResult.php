<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ModerationStatus;

final readonly class ModerationResult
{
    private ModerationStatus $status;

    public function __construct(
        ModerationStatus|string $status,
        private ?string $reason = null,
        private ?string $maskedContent = null,
        private ?string $originalContent = null,
    ) {
        $this->status = is_string($status)
            ? (ModerationStatus::tryFrom($status) ?? ModerationStatus::CLEAN)
            : $status;
    }

    public function isFlagged(): bool
    {
        return $this->status !== ModerationStatus::CLEAN;
    }

    public function isMasked(): bool
    {
        return $this->status === ModerationStatus::MASKED;
    }

    public function getStatus(): string
    {
        return $this->status->value;
    }

    public function getStatusEnum(): ModerationStatus
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
        return new self(status: ModerationStatus::CLEAN);
    }

    public static function masked(string $maskedContent, string $originalContent, string $reason = "Secret ou clé d'API détecté(e)"): self
    {
        return new self(
            status: ModerationStatus::MASKED,
            reason: $reason,
            maskedContent: $maskedContent,
            originalContent: $originalContent,
        );
    }

    public static function flagged(string $reason): self
    {
        return new self(
            status: ModerationStatus::FLAGGED,
            reason: $reason,
        );
    }
}
