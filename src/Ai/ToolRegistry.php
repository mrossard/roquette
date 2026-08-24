<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Tool\AiToolInterface;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Registry of the tools exposed to the LLM as native function calls.
 */
final readonly class ToolRegistry
{
    /** @var array<string, AiToolInterface> */
    private array $tools;

    /**
     * @param iterable<AiToolInterface> $tools
     */
    public function __construct(iterable $tools)
    {
        $indexed = [];
        foreach ($tools as $tool) {
            $indexed[$tool->getName()] = $tool;
        }
        $this->tools = $indexed;
    }

    /**
     * @return list<array{type: string, function: array<string, mixed>}>
     */
    public function getOpenAiTools(): array
    {
        $definitions = [];
        foreach ($this->tools as $name => $tool) {
            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParametersSchema(),
                ],
            ];
        }

        return $definitions;
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->tools);
    }

    public function get(string $name): ?AiToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    public function execute(ToolCall $call, ?int $authorUserId = null, ?int $workspaceId = null): string
    {
        $tool = $this->tools[$call->getName()] ?? null;
        if (!$tool) {
            return sprintf("Outil inconnu : '%s'.", $call->getName());
        }

        $args = $call->getArguments();
        $invokeArgs = [];
        $reflection = new \ReflectionMethod($tool, '__invoke');
        foreach ($reflection->getParameters() as $parameter) {
            $name = $parameter->getName();
            $val = match (true) {
                $name === 'authorUserId' && $authorUserId !== null => $authorUserId,
                $name === 'workspaceId' && $workspaceId !== null => $workspaceId,
                \array_key_exists($name, $args) => $args[$name],
                $name === 'channel' && \array_key_exists('channelSlug', $args) => $args['channelSlug'],
                $name === 'channelSlug' && \array_key_exists('channel', $args) => $args['channel'],
                $name === 'question' && \array_key_exists('pollQuestion', $args) => $args['pollQuestion'],
                $name === 'options' && \array_key_exists('pollOptions', $args) => $args['pollOptions'],
                $name === 'reminderText' && \array_key_exists('text', $args) => $args['text'],
                $name === 'delayMinutes' && \array_key_exists('delay', $args) => (int) $args['delay'],
                default => null,
            };
            if ($val !== null) {
                $invokeArgs[$name] = $val;
            }
        }

        try {
            return (string) $tool(...$invokeArgs);
        } catch (\Throwable $e) {
            return sprintf("Échec de l'exécution de l'outil '%s' : %s", $call->getName(), $e->getMessage());
        }
    }
}
