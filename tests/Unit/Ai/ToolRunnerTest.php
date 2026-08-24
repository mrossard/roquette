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
            ->expects($this->once())
            ->method('generateStreamWithTools')
            ->willReturn(
                (static function () {
                    yield new ToolCallComplete([new ToolCall('1', 'fake_tool', ['channelSlug' => 'general'])]);
                })(),
            );
        $llmService
            ->expects($this->once())
            ->method('generateTextStream')
            ->willReturn(
                (static function () {
                    yield 'Voilà !';
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
            ->expects($this->once())
            ->method('generateStreamWithTools')
            ->willReturn(
                (static function () {
                    yield new ToolCallComplete([new ToolCall('1', 'missing_tool', [])]);
                })(),
            );
        $llmService
            ->expects($this->once())
            ->method('generateTextStream')
            ->willReturnCallback(static function (string $prompt) use (&$calls): \Generator {
                $calls[] = $prompt;
                yield 'Réponse finale';
            });

        $runner = new ToolRunner($llmService, new ToolRegistry([]));

        $chunks = iterator_to_array($runner->streamResponse('Demande', 'sys', []));

        static::assertSame(['Réponse finale'], $chunks);
        static::assertStringContainsString('Outil inconnu', $calls[0]);
    }

    public function testDeduplicatesIdenticalToolCalls(): void
    {
        $llmService = $this->createMock(LlmService::class);
        $tool = new FakeTool();

        $llmService
            ->expects($this->once())
            ->method('generateStreamWithTools')
            ->willReturn(
                (static function () {
                    yield new ToolCallComplete([
                        new ToolCall('1', 'fake_tool', ['channelSlug' => 'general']),
                        new ToolCall('2', 'fake_tool', ['channelSlug' => 'general']),
                    ]);
                })(),
            );
        $llmService
            ->expects($this->once())
            ->method('generateTextStream')
            ->willReturn(
                (static function () {
                    yield 'C\'est fait !';
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

    public function testParsesPseudoToolCallDirectQueryArgument(): void
    {
        $llmService = $this->createMock(LlmService::class);
        $tool = new class implements \App\Ai\Tool\AiToolInterface {
            public function getName(): string { return 'search_messages'; }
            public function getDescription(): string { return 'Search'; }
            public function getParametersSchema(): array { return []; }
            public function requiresConfirmation(): bool { return false; }
            public function __invoke(string $query, ?string $channel = null): string {
                return 'Found previous COMEX document';
            }
        };

        $llmService
            ->expects($this->once())
            ->method('generateStreamWithTools')
            ->willReturn(
                (static function () {
                    yield new TextDelta("{\n  \"query\": \"COMEX précédent\",\n  \"channelSlug\": \"dm-robot-roquette-mrossard\"\n}");
                })(),
            );
        $llmService
            ->expects($this->once())
            ->method('generateTextStream')
            ->willReturn(
                (static function () {
                    yield 'Voici le résumé du COMEX précédent.';
                })(),
            );

        $runner = new ToolRunner($llmService, new ToolRegistry([$tool]));
        $executed = [];
        $chunks = iterator_to_array($runner->streamResponse(
            prompt: 'et dans le comex précédent?',
            systemPrompt: 'sys',
            tools: [],
            authorUserId: 42,
            workspaceId: 7,
            onToolExecuted: static function (string $name, string $result) use (&$executed): void {
                $executed[] = [$name, $result];
            },
        ));

        static::assertSame(['Voici le résumé du COMEX précédent.'], $chunks);
        static::assertSame('search_messages', $executed[0][0]);
    }
}

