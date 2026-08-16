<?php

declare(strict_types=1);

namespace App\Enum;

enum ModerationStatus: string
{
    case CLEAN = 'clean';
    case FLAGGED = 'flagged';
    case MASKED = 'masked';
    case PENDING = 'pending';
}
