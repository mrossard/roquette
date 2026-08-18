<?php

declare(strict_types=1);

namespace App\Dto\Account;

use Symfony\Component\HttpFoundation\Request;

final readonly class UpdateNotificationPreferencesDto
{
    public function __construct(
        public bool $mentionNotificationsEnabled,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            mentionNotificationsEnabled: (bool) $request->request->get('mentionNotificationsEnabled'),
        );
    }
}
