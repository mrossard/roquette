<?php

declare(strict_types=1);

namespace App\Service\SlashCommand;

use App\Entity\Channel;
use App\Entity\User;
use App\Service\SlashCommandResult;

final readonly class MeSlashCommand implements SlashCommandInterface
{
    public function getName(): string
    {
        return 'me';
    }

    public function processPreview(string $args): ?string
    {
        if ($args === '') {
            return '';
        }

        return '*' . $args . '*';
    }

    public function execute(string $args, Channel $channel, User $user, ?int $workspaceId = null): SlashCommandResult
    {
        return SlashCommandResult::transformed('/me' . ($args !== '' ? ' ' . $args : ''));
    }
}
