<?php

declare(strict_types=1);

namespace App\Enum;

enum UserPresenceStatus: string
{
    case AUTO = 'auto';
    case ONLINE = 'online';
    case AWAY = 'away';
    case BUSY = 'busy';
    case OFFLINE = 'offline';
}
