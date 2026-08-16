<?php

declare(strict_types=1);

namespace App\Service\SlashCommand;

use App\Entity\Channel;
use App\Entity\User;
use App\Service\SlashCommandResult;

final readonly class ShrugSlashCommand implements SlashCommandInterface
{
    public function getName(): string
    {
        return 'shrug';
    }

    public function processPreview(string $args): ?string
    {
        return ($args !== '' ? $args . ' ' : '') . '¯\_(ツ)_/¯';
    }

    public function execute(string $args, Channel $channel, User $user, ?int $workspaceId = null): SlashCommandResult
    {
        return SlashCommandResult::transformed(($args !== '' ? $args . ' ' : '') . '¯\_(ツ)_/¯');
    }
}
