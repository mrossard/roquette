<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\ToolRegistry;
use App\Ai\ToolRunner;
use App\Service\LlmService;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\ToolCall;

class ToolRunnerTest extends TestCase
{
    public function testStreamsTextWhenNoToolCallIsRequested(): void
    {
        $llmService = $this->createMock(LlmService::class);
        $llmService
            ->expects($this->once())
            ->method('generateStreamWithTools')
            ->willReturn(
                (static function () {
                    yield new TextDelta('Hello ');
                    yield new TextDelta('world!');
                })(),
            );

        $runner = new ToolRunner($llmService, new ToolRegistry([]));

        static::assertSame(['Hello ', 'world!'], iterator_to_array($runner->streamResponse('Hi', 'sys', [])));
    }

    public function testExecutesToolThenStreamsFinalAnswer(): void
    {
        $llmService = $this->createMock(LlmService::class);
        $tool = new FakeTool();

        $llmService
            ->expects($this->exactly(2))
            ->method('generateStreamWithTools')
            ->willReturnOnConsecutiveCalls(
                (static function () {
                    yield new ToolCallComplete([new ToolCall('1', 'fake_tool', ['channelSlug' => 'general'])]);
                })(),
                (static function () {
                    yield new TextDelta('Voilà !');
                })(),
            );

        $runner = new ToolRunner($llmService, new ToolRegistry([$tool]));
        $executed = [];
        $chunks = iterator_to_array($runner->streamResponse(
            prompt: 'Fais quelque chose',
            systemPrompt: 'sys',
            tools: [],
            authorUserId: 42,
            workspaceId: 7,
            onToolExecuted: static function (string $name, string $result) use (&$executed): void {
                $executed[] = [$name, $result];
            },
        ));

        static::assertSame(['Voilà !'], $chunks);
        static::assertSame(42, $tool->lastAuthorUserId);
        static::assertSame(7, $tool->lastWorkspaceId);
        static::assertSame('general', $tool->lastChannelSlug);
        static::assertSame([['fake_tool', 'Tool executed for general']], $executed);
    }

    public function testUnknownToolIsReportedBackToTheModel(): void
    {
        $llmService = $this->createMock(LlmService::class);
        $calls = [];

        $llmService
            ->expects($this->exactly(2))
            ->method('generateStreamWithTools')
            ->willReturnCallback(static function (string $prompt) use (&$calls): \Generator {
                $calls[] = $prompt;
                if (1 === count($calls)) {
                    return (static function () {
                        yield new ToolCallComplete([new ToolCall('1', 'missing_tool', [])]);
                    })();
                }

                return (static function () {
                    yield new TextDelta('Réponse finale');
                })();
            });

        $runner = new ToolRunner($llmService, new ToolRegistry([]));

        $chunks = iterator_to_array($runner->streamResponse('Demande', 'sys', []));

        static::assertSame(['Réponse finale'], $chunks);
        static::assertStringContainsString('Outil inconnu', $calls[1]);
    }

    public function testDeduplicatesIdenticalToolCalls(): void
    {
        $llmService = $this->createMock(LlmService::class);
        $tool = new FakeTool();

        $llmService
            ->expects($this->exactly(2))
            ->method('generateStreamWithTools')
            ->willReturnOnConsecutiveCalls(
                (static function () {
                    yield new ToolCallComplete([
                        new ToolCall('1', 'fake_tool', ['channelSlug' => 'general']),
                        new ToolCall('2', 'fake_tool', ['channelSlug' => 'general']),
                    ]);
                })(),
                (static function () {
                    yield new TextDelta('C\'est fait !');
                })(),
            );

        $runner = new ToolRunner($llmService, new ToolRegistry([$tool]));
        $executed = [];
        $chunks = iterator_to_array($runner->streamResponse(
            prompt: 'Crée ça deux fois',
            systemPrompt: 'sys',
            tools: [],
            authorUserId: 42,
            workspaceId: 7,
            onToolExecuted: static function (string $name, string $result) use (&$executed): void {
                $executed[] = [$name, $result];
            },
        ));

        static::assertSame(['C\'est fait !'], $chunks);
        static::assertSame([['fake_tool', 'Tool executed for general']], $executed);
    }

    public function testConfirmationRequiredToolIsPausedAndAsksUser(): void
    {
        $llmService = $this->createMock(LlmService::class);
        $tool = new ConfirmationFakeTool();

        $llmService
            ->expects($this->once())
            ->method('generateStreamWithTools')
            ->willReturn(
                (static function () {
                    yield new ToolCallComplete([new ToolCall('1', 'confirm_tool', ['channelSlug' => 'general'])]);
                })(),
            );
        $llmService
            ->expects($this->once())
            ->method('generateTextStream')
            ->willReturn(
                (static function () {
                    yield 'Voulez-vous confirmer cette action ?';
                })(),
            );

        $runner = new ToolRunner($llmService, new ToolRegistry([$tool]));
        $confirmationRequests = [];
        $executed = [];

        $chunks = iterator_to_array($runner->streamResponse(
            prompt: 'Crée un sondage',
            systemPrompt: 'sys',
            tools: [],
            authorUserId: 42,
            workspaceId: 7,
            onToolExecuted: static function (string $name, string $result) use (&$executed): void {
                $executed[] = [$name, $result];
            },
            onConfirmationRequired: static function (string $name, array $arguments) use (
                &$confirmationRequests,
            ): void {
                $confirmationRequests[] = [$name, $arguments];
            },
        ));

        static::assertSame(['Voulez-vous confirmer cette action ?'], $chunks);
        static::assertFalse($tool->executed);
        static::assertSame([['confirm_tool', ['channelSlug' => 'general']]], $confirmationRequests);
        static::assertSame([], $executed);
    }
}
