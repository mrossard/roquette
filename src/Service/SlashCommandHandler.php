<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\User;
use App\Service\SlashCommand\SlashCommandInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class SlashCommandHandler
{
    /**
     * @var array<string, SlashCommandInterface>
     */
    private array $commands = [];

    /**
     * @param iterable<SlashCommandInterface> $commands
     */
    public function __construct(#[AutowireIterator('app.slash_command')] iterable $commands = [])
    {
        foreach ($commands as $command) {
            $this->commands[strtolower($command->getName())] = $command;
        }
    }

    /**
     * Transforms preview text for commands supporting live preview.
     */
    public function processPreview(string $content): string
    {
        $trimmed = trim($content);
        if (!str_starts_with($trimmed, '/')) {
            return $content;
        }

        $parts = explode(' ', $trimmed, 2);
        $commandName = strtolower(substr($parts[0], 1));
        $args = ($parts[1] ?? null) !== null ? trim($parts[1]) : '';

        $cmd = $this->commands[$commandName] ?? null;
        if ($cmd !== null) {
            $preview = $cmd->processPreview($args);
            if ($preview !== null) {
                return $preview;
            }
        }

        return $content;
    }

    /**
     * Processes a slash command in the context of publishing a message.
     *
     * @param string  $messageText the raw message text
     * @param Channel $channel     the active channel
     * @param User    $user        the current user
     */
    public function process(
        string $messageText,
        Channel $channel,
        User $user,
        ?int $workspaceId = null,
    ): SlashCommandResult {
        $trimmedMsg = trim($messageText);
        if (!str_starts_with($trimmedMsg, '/')) {
            return SlashCommandResult::unhandled($messageText);
        }

        $parts = explode(' ', $trimmedMsg, 2);
        $commandName = strtolower(substr($parts[0], 1));
        $args = ($parts[1] ?? null) !== null ? trim($parts[1]) : '';

        $cmd = $this->commands[$commandName] ?? null;
        if ($cmd !== null) {
            return $cmd->execute($args, $channel, $user, $workspaceId);
        }

        return SlashCommandResult::unhandled($messageText);
    }
}
