<?php

declare(strict_types=1);

namespace App\Ai;

use App\Service\LlmService;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\ToolCall;

use function array_key_exists;
use function implode;
use function json_encode;
use function microtime;
use function sprintf;
use function trim;

/**
 * Executes the model tool-calling loop and streams the final answer.
 */
final readonly class ToolRunner
{
    private PseudoToolCallParser $pseudoCallParser;

    public function __construct(
        private LlmService $llmService,
        private ToolRegistry $toolRegistry,
        private ?LoggerInterface $logger = null,
        ?PseudoToolCallParser $pseudoCallParser = null,
    ) {
        $this->pseudoCallParser = $pseudoCallParser ?? new PseudoToolCallParser($toolRegistry);
    }

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
        [$toolCalls, $textDeltas] = $this->consumeIterationStream($prompt, $systemPrompt, $tools);

        // If no tool was requested, yield the direct text response
        if ($toolCalls === null || [] === $toolCalls) {
            foreach ($textDeltas as $textChunk) {
                yield $textChunk;
            }

            return;
        }

        $executedCalls = [];
        $uniqueCalls = $this->filterNewCalls($toolCalls, $executedCalls);

        [$results, $confirmationPending] = $this->executeToolBatch(
            $uniqueCalls,
            $authorUserId,
            $workspaceId,
            $onToolExecuted,
            $onConfirmationRequired,
        );

        if ($confirmationPending) {
            $confirmationPrompt = $this->buildConfirmationPrompt($prompt, $results);
            yield from $this->llmService->generateTextStream($confirmationPrompt, $systemPrompt);

            return;
        }

        $executionPrompt = $this->buildToolExecutionPrompt($prompt, $results);
        $producedText = false;

        foreach ($this->llmService->generateTextStream($executionPrompt, $systemPrompt) as $chunk) {
            if ('' !== trim($chunk)) {
                $producedText = true;
            }
            yield $chunk;
        }

        if (!$producedText && $results !== []) {
            yield implode("\n", $results);
        }
    }

    /**
     * @param list<array{type: string, function: array<string, mixed>}> $tools
     * @return array{0: ?array<ToolCall>, 1: list<string>}
     */
    private function consumeIterationStream(string $currentPrompt, ?string $systemPrompt, array $tools): array
    {
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
            $parsedToolCall = $this->pseudoCallParser->parse($textBuffer);
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
            if (
                $onConfirmationRequired !== null
                && $this->toolRegistry->get($call->getName())?->requiresConfirmation()
            ) {
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
        return (
            "Une action demandée nécessite une confirmation de l'utilisateur :\n"
            . implode("\n", $results)
            . "\n\nRéponds maintenant brièvement à l'utilisateur : explique l'action demandée et demande-lui de la confirmer (via le bouton ou en répondant simplement 'ok'). N'appelle aucun outil.\n"
            . $originalPrompt
        );
    }

    /**
     * @param list<string> $results
     */
    private function buildToolExecutionPrompt(string $originalPrompt, array $results): string
    {
        return (
            "Résultats des outils exécutés :\n"
            . implode("\n", $results)
            . "\n\nRéponds maintenant à l'utilisateur pour satisfaire sa demande en t'appuyant sur les résultats ci-dessus :\n"
            . $originalPrompt
        );
    }
}
