<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\AI\Platform\Result\ToolCall;

final readonly class PseudoToolCallParser
{
    public function __construct(
        private ToolRegistry $toolRegistry,
    ) {}

    public function parse(string $text): ?ToolCall
    {
        $data = JsonExtractor::extractArray($text);
        if (!is_array($data)) {
            return null;
        }

        [$name, $rawArgs] = $this->extractExplicitNameAndArgs($data);
        if ($name === null) {
            $name = $this->inferToolNameFromArgs($data);
            $rawArgs = $data;
        }

        if ($name === null || !$this->toolRegistry->has($name)) {
            return null;
        }

        $args = $this->normalizeToolArguments($rawArgs);

        return new ToolCall(uniqid('pseudo_call_', true), $name, $args);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: ?string, 1: mixed}
     */
    private function extractExplicitNameAndArgs(array $data): array
    {
        $call0 = $data['tool_calls'][0] ?? null;
        if (is_array($call0)) {
            return $this->extractFromToolCallItem($call0);
        }

        $fn = $data['function'] ?? null;
        if (is_array($fn)) {
            return [$fn['name'] ?? null, $fn['arguments'] ?? $fn['parameters'] ?? []];
        }

        $nameCandidate = $data['tool'] ?? $data['name'] ?? $data['function'] ?? $data['tool_name'] ?? $data['action'] ?? $data['call'] ?? null;
        if (is_string($nameCandidate) && $this->toolRegistry->has($nameCandidate)) {
            return [$nameCandidate, $this->extractNamedCandidateArgs($data, $nameCandidate)];
        }

        return [null, []];
    }

    /**
     * @param array<string, mixed> $callItem
     * @return array{0: ?string, 1: mixed}
     */
    private function extractFromToolCallItem(array $callItem): array
    {
        $fn = $callItem['function'] ?? null;
        if (is_array($fn)) {
            return [$fn['name'] ?? null, $fn['arguments'] ?? $fn['parameters'] ?? []];
        }

        return [$callItem['name'] ?? $callItem['tool'] ?? null, $callItem['arguments'] ?? $callItem['args'] ?? []];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractNamedCandidateArgs(array $data, string $nameCandidate): mixed
    {
        $args = $data['arguments'] ?? $data['parameters'] ?? $data['params'] ?? $data['args'] ?? $data['action'] ?? [];
        if ((!is_array($args) && !is_string($args)) || $args === [] || $args === $nameCandidate) {
            return array_diff_key($data, array_flip(['tool', 'name', 'function', 'tool_name', 'action', 'call', 'type']));
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function inferToolNameFromArgs(array $data): ?string
    {
        if (array_key_exists('query', $data)) {
            return 'search_messages';
        }
        if (array_key_exists('reminderText', $data) || array_key_exists('delayMinutes', $data)) {
            return 'schedule_reminder';
        }
        if (array_key_exists('pollQuestion', $data) || (array_key_exists('question', $data) && array_key_exists('options', $data))) {
            return 'create_poll';
        }
        if (array_key_exists('channelSlug', $data) && !array_key_exists('query', $data) && !array_key_exists('reminderText', $data)) {
            return 'summarize_channel';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeToolArguments(mixed $rawArgs): array
    {
        if (is_string($rawArgs)) {
            $decoded = json_decode($rawArgs, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            return [];
        }

        return is_array($rawArgs) ? $rawArgs : [];
    }
}
