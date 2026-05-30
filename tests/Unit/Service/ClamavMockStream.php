<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

class ClamavMockStream
{
    public $context;
    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        $data = ClamavServiceTestState::$response;
        $ret = substr($data, $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }

    public function stream_write(string $data): int
    {
        // Accept and discard write data
        return strlen($data);
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(ClamavServiceTestState::$response);
    }

    public function stream_stat(): array
    {
        return [];
    }
}
