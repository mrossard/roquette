<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class ParsedSearchQuery
{
    public function __construct(
        public string $rawQuery,
        public string $textQuery,
        public ?string $authorUsername = null,
        public ?string $channelName = null,
        public ?bool $hasFile = null,
        public ?string $fileType = null,
    ) {}

    public function hasFilters(): bool
    {
        return $this->authorUsername !== null
            || $this->channelName !== null
            || $this->hasFile !== null;
    }

    public function isEmpty(): bool
    {
        return $this->rawQuery === '';
    }
}
