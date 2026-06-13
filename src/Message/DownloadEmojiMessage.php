<?php

declare(strict_types=1);

namespace App\Message;

class DownloadEmojiMessage
{
    public function __construct(
        private readonly string $filename,
    ) {}

    public function getFilename(): string
    {
        return $this->filename;
    }
}
