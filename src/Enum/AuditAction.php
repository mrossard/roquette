<?php

declare(strict_types=1);

namespace App\Enum;

enum AuditAction: string
{
    case USER_BAN = 'user_ban';
    case USER_UNBAN = 'user_unban';
    case CHANNEL_EXPORT = 'channel_export';
    case EXPORT_DOWNLOAD = 'export_download';
    case EXPORT_DELETE = 'export_delete';
    case CHANNEL_CREATE = 'channel_create';
    case CHANNEL_DELETE = 'channel_delete';
    case GROUP_CREATE = 'group_create';
    case GROUP_DELETE = 'group_delete';
}
