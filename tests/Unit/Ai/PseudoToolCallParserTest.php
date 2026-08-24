<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\PseudoToolCallParser;
use App\Ai\Tool\AiToolInterface;
use App\Ai\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class PseudoToolCallParserTest extends TestCase
{
    private function createRegistry(): ToolRegistry
    {
        $searchTool = new class implements AiToolInterface {
            public function getName(): string { return 'search_messages'; }
            public function getDescription(): string { return ''; }
            public function getParametersSchema(): array { return []; }
            public function requiresConfirmation(): bool { return false; }
            public function __invoke(string $query, ?string $channel = null): string { return ''; }
        };

        $reminderTool = new class implements AiToolInterface {
            public function getName(): string { return 'schedule_reminder'; }
            public function getDescription(): string { return ''; }
            public function getParametersSchema(): array { return []; }
            public function requiresConfirmation(): bool { return true; }
            public function __invoke(string $reminderText, int $delayMinutes): string { return ''; }
        };

        return new ToolRegistry([$searchTool, $reminderTool]);
    }

    public function testParsesDirectParameterDictionary(): void
    {
        $parser = new PseudoToolCallParser($this->createRegistry());
        $call = $parser->parse("{\n  \"query\": \"COMEX précédent\",\n  \"channelSlug\": \"dm-robot-roquette-mrossard\"\n}");

        static::assertNotNull($call);
        static::assertSame('search_messages', $call->getName());
        static::assertSame('COMEX précédent', $call->getArguments()['query']);
    }

    public function testParsesNestedOpenAiFormat(): void
    {
        $parser = new PseudoToolCallParser($this->createRegistry());
        $json = json_encode([
            'tool_calls' => [
                [
                    'function' => [
                        'name' => 'search_messages',
                        'arguments' => json_encode(['query' => 'facture']),
                    ],
                ],
            ],
        ]);

        $call = $parser->parse((string) $json);

        static::assertNotNull($call);
        static::assertSame('search_messages', $call->getName());
        static::assertSame('facture', $call->getArguments()['query']);
    }

    public function testParsesActionOrToolNameWrapper(): void
    {
        $parser = new PseudoToolCallParser($this->createRegistry());
        $call = $parser->parse('{"tool": "schedule_reminder", "reminderText": "Finir le rapport", "delayMinutes": 15}');

        static::assertNotNull($call);
        static::assertSame('schedule_reminder', $call->getName());
        static::assertSame('Finir le rapport', $call->getArguments()['reminderText']);
        static::assertSame(15, $call->getArguments()['delayMinutes']);
    }

    public function testReturnsNullForInvalidOrNonToolJson(): void
    {
        $parser = new PseudoToolCallParser($this->createRegistry());
        static::assertNull($parser->parse('Ceci est une réponse texte normale.'));
        static::assertNull($parser->parse('{"foo": "bar"}'));
    }
}
