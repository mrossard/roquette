<?php

declare(strict_types=1);

namespace App\Ai;

use App\Service\LlmService;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Executes the model tool-calling loop and streams the final answer.
 */
final readonly class ToolRunner
{
    private const MAX_TOOL_ITERATIONS = 3;

    public function __construct(
        private LlmService $llmService,
        private ToolRegistry $toolRegistry,
    ) {}

    /**
     * Streams the assistant response, executing any tool call requested by the model.
     *
     * @param string $prompt The user prompt to send to the model.
     * @param string|null $systemPrompt Optional override for the system prompt.
     * @param list<array{type: string, function: array<string, mixed>}> $tools Normalized OpenAI tool definitions.
     * @param int|null $authorUserId ID of the user authoring tool actions (injected into tools that support it).
     * @param int|null $workspaceId ID of the current workspace (injected into tools that support it).
     * @param callable(string, string): void|null $onToolExecuted Called with the tool name and its result after execution.
     * @return \Generator<string> Yields the text chunks of the final answer.
     */
    public function streamResponse(
        string $prompt,
        ?string $systemPrompt,
        array $tools,
        ?int $authorUserId = null,
        ?int $workspaceId = null,
        ?callable $onToolExecuted = null,
    ): \Generator {
        $currentPrompt = $prompt;
        $producedText = false;
        $allExecutedResults = [];

        for ($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++) {
            $toolCalls = null;
            $textDeltas = [];
            $textBuffer = '';

            foreach ($this->llmService->generateStreamWithTools($currentPrompt, $systemPrompt, $tools) as $delta) {
                if ($delta instanceof ToolCallComplete) {
                    $toolCalls = $delta->getToolCalls();
                    break;
                }

                if ($delta instanceof TextDelta) {
                    $textDeltas[] = $delta->getText();
                    $textBuffer .= $delta->getText();
                }
            }

            // Fallback: if native tool calls were not received, attempt to parse pseudo-JSON tool calls from textBuffer
            if (($toolCalls === null || [] === $toolCalls) && '' !== trim($textBuffer)) {
                $parsedToolCall = $this->parsePseudoToolCall($textBuffer);
                if ($parsedToolCall !== null) {
                    $toolCalls = [$parsedToolCall];
                    $textDeltas = []; // Clear textDeltas so the raw JSON is not yielded to the user
                }
            }

            // Yield accumulated text deltas if it was not a tool call JSON
            foreach ($textDeltas as $textChunk) {
                if ('' !== trim($textChunk)) {
                    $producedText = true;
                }
                yield $textChunk;
            }

            if ($toolCalls === null || [] === $toolCalls) {
                return;
            }

            // Deduplicate tool calls to prevent repeating identical actions (e.g. creating 3 identical reminders)
            $newCalls = [];
            foreach ($toolCalls as $call) {
                $callKey = $call->getName() . ':' . json_encode($call->getArguments());
                if (isset($executedCalls[$callKey])) {
                    continue;
                }
                $executedCalls[$callKey] = true;
                $newCalls[] = $call;
            }

            if ([] === $newCalls) {
                if (!$producedText && !empty($allExecutedResults)) {
                    yield implode("\n", $allExecutedResults);
                    $producedText = true;
                }
                return;
            }

            $results = [];
            foreach ($newCalls as $call) {
                $result = $this->toolRegistry->execute($call, $authorUserId, $workspaceId);
                if ($onToolExecuted !== null) {
                    $onToolExecuted($call->getName(), $result);
                }
                $results[] = $result;
                $allExecutedResults[] = $result;
            }

            $currentPrompt = "Résultats des outils exécutés :\n"
                . implode("\n", $results)
                . "\n\nRéponds maintenant brièvement à l'utilisateur pour confirmer l'action réalisée en utilisant exactement l'information de confirmation ci-dessus :\n"
                . $prompt;
        }

        foreach ($this->llmService->generateTextStream($currentPrompt, $systemPrompt) as $chunk) {
            if ('' !== trim($chunk)) {
                $producedText = true;
            }
            yield $chunk;
        }

        if (!$producedText && !empty($allExecutedResults)) {
            yield implode("\n", $allExecutedResults);
        }
    }

    /**
     * Attempts to parse raw JSON emitted in response text into a ToolCall object.
     * Handles formats like:
     * {"tool": "schedule_reminder", "action": {"channelSlug": "...", ...}}
     * {"name": "schedule_reminder", "arguments": {...}}
     * {"function": "schedule_reminder", "parameters": {...}}
     */
    private function parsePseudoToolCall(string $text): ?ToolCall
    {
        $trimmed = trim($text);

        // Strip markdown code blocks if present (e.g. ```json ... ```)
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        if (!str_starts_with($trimmed, '{') || !str_ends_with($trimmed, '}')) {
            return null;
        }

        try {
            $data = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($data)) {
                return null;
            }

            $name = $data['tool'] ?? $data['name'] ?? $data['function'] ?? null;
            if (!\is_string($name) || '' === $name) {
                return null;
            }

            $args = $data['action'] ?? $data['arguments'] ?? $data['parameters'] ?? $data['args'] ?? [];
            if (!\is_array($args)) {
                $args = [];
            }

            return new ToolCall(uniqid('pseudo_call_', true), $name, $args);
        } catch (\Throwable) {
            return null;
        }
    }
}
