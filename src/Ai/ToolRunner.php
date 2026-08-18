<?php

declare(strict_types=1);

namespace App\Ai;

use App\Service\LlmService;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\ToolCall;

use function array_key_exists;
use function is_array;
use function is_string;
use function microtime;
use function sprintf;
use function trim;
use function implode;

/**
 * Executes the model tool-calling loop and streams the final answer.
 */
final readonly class ToolRunner
{
    private const int MAX_TOOL_ITERATIONS = 3;

    public function __construct(
        private LlmService $llmService,
        private ToolRegistry $toolRegistry,
        private ?LoggerInterface $logger = null,
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
     * @param callable(string, array<string, mixed>): void|null $onConfirmationRequired Called with the tool name and its arguments when a side-effect tool needs user confirmation.
     * @return \Generator<string> Yields the text chunks of the final answer.
     */
    public function streamResponse(
        string $prompt,
        ?string $systemPrompt,
        array $tools,
        ?int $authorUserId = null,
        ?int $workspaceId = null,
        ?callable $onToolExecuted = null,
        ?callable $onConfirmationRequired = null,
    ): \Generator {
        $currentPrompt = $prompt;
        $producedText = false;
        $allExecutedResults = [];
        $executedCalls = [];

        for ($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++) {
            [$toolCalls, $textDeltas] = $this->consumeIterationStream($currentPrompt, $systemPrompt, $tools);

            foreach ($textDeltas as $textChunk) {
                if ('' !== trim($textChunk)) {
                    $producedText = true;
                }
                yield $textChunk;
            }

            if ($toolCalls === null || [] === $toolCalls) {
                return;
            }

            $newCalls = $this->filterNewCalls($toolCalls, $executedCalls);
            if ([] === $newCalls) {
                if (!$producedText && $allExecutedResults !== []) {
                    yield implode("\n", $allExecutedResults);
                }
                return;
            }

            [$results, $confirmationPending] = $this->executeToolBatch(
                $newCalls,
                $authorUserId,
                $workspaceId,
                $onToolExecuted,
                $onConfirmationRequired,
            );

            foreach ($results as $res) {
                $allExecutedResults[] = $res;
            }

            if ($confirmationPending) {
                $currentPrompt = $this->buildConfirmationPrompt($prompt, $results);
                break;
            }

            $currentPrompt = $this->buildToolExecutionPrompt($prompt, $results);
        }

        foreach ($this->llmService->generateTextStream($currentPrompt, $systemPrompt) as $chunk) {
            if ('' !== trim($chunk)) {
                $producedText = true;
            }
            yield $chunk;
        }

        if (!$producedText && $allExecutedResults !== []) {
            yield implode("\n", $allExecutedResults);
        }
    }

    /**
     * @param list<array{type: string, function: array<string, mixed>}> $tools
     * @return array{0: ?array<ToolCall>, 1: list<string>}
     */
    private function consumeIterationStream(
        string $currentPrompt,
        ?string $systemPrompt,
        array $tools,
    ): array {
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

        if (($toolCalls === null || [] === $toolCalls) && '' !== trim($textBuffer)) {
            $parsedToolCall = $this->parsePseudoToolCall($textBuffer);
            if ($parsedToolCall !== null) {
                $toolCalls = [$parsedToolCall];
                $textDeltas = [];
            }
        }

        return [$toolCalls, $textDeltas];
    }

    /**
     * @param array<ToolCall> $toolCalls
     * @param array<string, bool> $executedCalls
     * @return list<ToolCall>
     */
    private function filterNewCalls(array $toolCalls, array &$executedCalls): array
    {
        $newCalls = [];
        foreach ($toolCalls as $call) {
            $callKey = $call->getName() . ':' . json_encode($call->getArguments());
            if (array_key_exists($callKey, $executedCalls)) {
                continue;
            }
            $executedCalls[$callKey] = true;
            $newCalls[] = $call;
        }

        return $newCalls;
    }

    /**
     * @param list<ToolCall> $calls
     * @return array{0: list<string>, 1: bool}
     */
    private function executeToolBatch(
        array $calls,
        ?int $authorUserId,
        ?int $workspaceId,
        ?callable $onToolExecuted,
        ?callable $onConfirmationRequired,
    ): array {
        $confirmationPending = false;
        $results = [];

        foreach ($calls as $call) {
            if ($onConfirmationRequired !== null && $this->toolRegistry->get($call->getName())?->requiresConfirmation()) {
                $confirmationPending = true;
                $this->logger?->info('Tool action requires user confirmation', [
                    'tool' => $call->getName(),
                    'arguments' => $call->getArguments(),
                    'authorUserId' => $authorUserId,
                ]);
                $onConfirmationRequired($call->getName(), $call->getArguments());
                $results[] = sprintf(
                    "L'action de l'outil '%s' nécessite une confirmation de l'utilisateur.\n"
                    . "N'appelle plus aucun outil et demande à l'utilisateur de confirmer l'action soit via le bouton de confirmation, soit en répondant simplement 'ok'.",
                    $call->getName(),
                );
                continue;
            }

            $startedAt = microtime(true);
            $result = $this->toolRegistry->execute($call, $authorUserId, $workspaceId);
            $this->logger?->info('Tool executed', [
                'tool' => $call->getName(),
                'durationMs' => (int) ((microtime(true) - $startedAt) * 1000),
                'authorUserId' => $authorUserId,
            ]);
            if ($onToolExecuted !== null) {
                $onToolExecuted($call->getName(), $result);
            }
            $results[] = $result;
        }

        return [$results, $confirmationPending];
    }

    /**
     * @param list<string> $results
     */
    private function buildConfirmationPrompt(string $originalPrompt, array $results): string
    {
        return "Une action demandée nécessite une confirmation de l'utilisateur :\n"
            . implode("\n", $results)
            . "\n\nRéponds maintenant brièvement à l'utilisateur : explique l'action demandée et demande-lui de la confirmer (via le bouton ou en répondant simplement 'ok'). N'appelle aucun outil.\n"
            . $originalPrompt;
    }

    /**
     * @param list<string> $results
     */
    private function buildToolExecutionPrompt(string $originalPrompt, array $results): string
    {
        return "Résultats des outils exécutés :\n"
            . implode("\n", $results)
            . "\n\nRéponds maintenant brièvement à l'utilisateur pour confirmer l'action réalisée en utilisant exactement l'information de confirmation ci-dessus :\n"
            . $originalPrompt;
    }

    /**
     * Attempts to parse raw JSON emitted in response text into a ToolCall object.
     */
    private function parsePseudoToolCall(string $text): ?ToolCall
    {
        $data = JsonExtractor::extractArray($text);
        if (!is_array($data)) {
            return null;
        }

        $name = $data['tool'] ?? $data['name'] ?? $data['function'] ?? null;
        if (!is_string($name) || '' === $name) {
            return null;
        }

        $args = $data['action'] ?? $data['arguments'] ?? $data['parameters'] ?? $data['args'] ?? [];
        if (!is_array($args)) {
            $args = [];
        }

        return new ToolCall(uniqid('pseudo_call_', true), $name, $args);
    }
}
