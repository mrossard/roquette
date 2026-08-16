<?php

declare(strict_types=1);

namespace App\Service\SlashCommand;

use App\Entity\Channel;
use App\Entity\User;
use App\Service\SlashCommandResult;

interface SlashCommandInterface
{
    /**
     * The command name without the leading slash (e.g. "color", "help", "poll", "shrug", "me").
     */
    public function getName(): string;

    /**
     * Transforms preview text if this command supports live message preview.
     * Returns null if no custom preview is provided.
     */
    public function processPreview(string $args): ?string;

    /**
     * Executes the command.
     */
    public function execute(string $args, Channel $channel, User $user, ?int $workspaceId = null): SlashCommandResult;
}
