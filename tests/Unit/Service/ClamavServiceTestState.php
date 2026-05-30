<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

class ClamavServiceTestState
{
    public static bool $shouldFailFsockopen = false;
    public static string $response = '';
    public static bool $shouldFailFopen = false;
    public static bool $streamWrapperRegistered = false;
}
